<?php
/**
 * داشبۆردی بەکارهێنەر - user/dashboard/index.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/company_computed_debt.php';
require_once __DIR__ . '/../../includes/theme_bootstrap.php';
require_once __DIR__ . '/../../includes/wallet_service.php';

if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
    $db = new Database();
    $GLOBALS['conn'] = $db->connect();
}
$conn = $GLOBALS['conn'];

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
// تێبینی: داشبۆرد خۆی تەرگێتی ڕیدایرێکتی هەموو ڕەتکردنەوەکانی دەسەڵاتە، بۆیە
// نابێت لێرە ڕیدایرێکت بکرێتەوە بۆ خۆی (دەبێتە هۆی ERR_TOO_MANY_REDIRECTS).
// کارمەندی بێ دەسەڵات داشبۆردێکی بەتاڵ دەبینێت (بەشەکان هەریەکە بە دەسەڵاتی خۆی پارێزراون).

// دڵنیابوون لە هاوتا بوونی session لەگەڵ داتابەیس (بەرگری لە ناسنامەی هەڵە)
if (!empty($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    $subId = (int) ($currentUser['sub_user_id'] ?? 0);
    $verifyStmt = $conn->prepare("SELECT id FROM sub_users WHERE id = ? AND main_user_id = ? AND is_active = 1 LIMIT 1");
    $verifyStmt->bind_param("ii", $subId, $userId);
    $verifyStmt->execute();
    $subOk = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();
    if (!$subOk) {
        if (isset($_COOKIE['remember_me'])) {
            $cp = explode(':', $_COOKIE['remember_me'], 2);
            if (count($cp) === 2) {
                Security::deleteRememberToken($cp[0]);
            }
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        }
        SessionManager::logout('user');
        setMessage('دەستپێگەیشتن نادروست بوو، تکایە دووبارە بچۆرە ژوورەوە', 'danger');
        redirect(url('user/auth/login.php'));
    }
} else {
    $verifyStmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $verifyStmt->bind_param("i", $userId);
    $verifyStmt->execute();
    $mainOk = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();
    if (!$mainOk) {
        if (isset($_COOKIE['remember_me'])) {
            $cp = explode(':', $_COOKIE['remember_me'], 2);
            if (count($cp) === 2) {
                Security::deleteRememberToken($cp[0]);
            }
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        }
        SessionManager::logout('user');
        setMessage('دەستپێگەیشتن نادروست بوو، تکایە دووبارە بچۆرە ژوورەوە', 'danger');
        redirect(url('user/auth/login.php'));
    }
}

// تەنها بۆ پاراستنی data integrity ـە: دروستکردنی قاسەی سەرەکی نابێت بە feature toggle ـی wallets ببەسترێتەوە.
$ensureWalletResult = ['success' => false, 'message' => null];
try {
    $ensureWalletResult = walletEnsureDefaultForUser($conn, (int)$userId, 'قاسەی سەرەکی');
    if (empty($ensureWalletResult['success'])) {
        error_log('Dashboard wallet ensure failed for user ' . (int)$userId . ': ' . (string)($ensureWalletResult['message'] ?? 'unknown error'));
    }
} catch (Throwable $e) {
    error_log('Dashboard wallet ensure exception for user ' . (int)$userId . ': ' . $e->getMessage());
}

// پشکنین و ناردنی ڕاپۆرتی ئۆتۆماتیکی تیلیگرام (بە بێدەنگی)
if (file_exists(__DIR__ . '/../telegram/auto_send.php')) {
    try {
        require_once __DIR__ . '/../telegram/auto_send.php';
        @checkAndSendTelegramReport($userId);
    } catch (Exception $e) {
        // بێدەنگانە هەڵە نادەین، بەردەوام دەبین
    }
}

$packageInfo = getUserPackageInfo($userId);
$dashboardPermissionContext = [
    'route' => '/user/dashboard/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
];
$canViewModule = static function ($module) use ($currentUser, $dashboardPermissionContext) {
    $result = authorize($currentUser, $module . '.view', $dashboardPermissionContext);
    return !empty($result['allowed']);
};

$canViewSalesCard = $canViewModule('pos') || $canViewModule('products') || $canViewModule('returns');
$canViewProductsCard = $canViewModule('products') || $canViewModule('inventory') || $canViewModule('categories');
$canViewCustomersCard = $canViewModule('customers') || $canViewModule('debts') || $canViewModule('customer_info');
$canViewExpensesCard = $canViewModule('expenses') || $canViewModule('expense_credits') || $canViewModule('expense_stats');
$canViewWalletsCard = $canViewModule('wallets');
$canViewReportsCard = $canViewModule('reports') || $canViewModule('profits') || $canViewModule('notebooks');
$canViewCompaniesCard = $canViewModule('companies') || $canViewModule('purchases');
$canViewNotebooksCard = $canViewModule('notebooks');
$canViewSettingsCard = $canViewModule('settings');
$canViewEmployeesCard = $canViewModule('employees');
$canViewChatbotCard = $canViewModule('dashboard');
$canViewMedicalStaffCard = false;
$canViewCosmeticStaffCard = false;
$canViewLabStaffCard = false;

$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
if ($settingsStmt) {
    $settingsStmt->bind_param('i', $userId);
    $settingsStmt->execute();
    $settingsRow = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();

    $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
    $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
    $isMedicalCenterBusinessType = (
        $businessTypeId === 3 ||
        $businessTypeCode === 'pharmacy_and_medical_center'
    );
    $canViewMedicalStaffCard = $isMedicalCenterBusinessType;
    $canViewCosmeticStaffCard = $isMedicalCenterBusinessType;
    $canViewLabStaffCard = $isMedicalCenterBusinessType;
}

$hasAnyRestrictedDashboardCard = $canViewSalesCard
    || $canViewProductsCard
    || $canViewCustomersCard
    || $canViewExpensesCard
    || $canViewReportsCard
    || $canViewCompaniesCard
    || $canViewNotebooksCard
    || $canViewSettingsCard
    || $canViewEmployeesCard
    || $canViewChatbotCard
    || $canViewMedicalStaffCard
    || $canViewCosmeticStaffCard
    || $canViewLabStaffCard;

// چێکردنی دۆخی ئاگادارکردنەوە بۆ بەسەرچوونی ئەکاونت
$showExpirationWarning = false;
$expirationFormatted = null;

try {
    $stmt = $conn->prepare("SELECT expiration_date FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row && !empty($row['expiration_date'])) {
            $now = new DateTime();
            $expiration = new DateTime($row['expiration_date']);

            // تەنها کاتێک ئاگادارکردنەوەکە پیشان بدە کە ئەکاونتەکە ھێشتا بوەستاو نییە
            if ($expiration > $now) {
                $interval = $now->diff($expiration);
                $days = (int)$interval->format('%a');
                $totalHours = $days * 24 + (int)$interval->h;

                // کاتێک لە ٧٢ کاتژمێر (سێ ڕۆژ) کەمتر ماوە بۆ بەسەرچوون
                if ($totalHours <= 72) {
                    $showExpirationWarning = true;
                    $expirationFormatted = $expiration->format('Y/m/d - H:i');
                }
            }
        }
    }
} catch (Exception $e) {
    // بێدەنگانە هەڵەکە بوەستێنە، داشبۆردی بەکارهێنەر بوەست مەکە
}

// وەرگرتنی ئامارەکان
$stats = [
    'total_products' => 0,
    'low_stock_products' => 0,
    'expired_products' => 0,
    'categories' => 0,
    'today_sales' => 0,
    'today_revenue' => 0,
    'monthly_sales' => 0,
    'monthly_revenue' => 0,
    'total_customers' => 0,
    'active_customers' => 0,
    'active_debts' => 0,
    'completed_debts' => 0,
    'remaining_debt' => 0,
    'received_payments' => 0,
    'total_returns' => 0,
    'today_returns' => 0,
    'today_return_amount' => 0,
    'monthly_returns' => 0,
    'monthly_return_amount' => 0
];



// گشتی کاڵاکان و ئامارە سەرەکییەکان
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM products WHERE user_id = $userId");
    if ($result) {
        $stats['total_products'] = $result->fetch_assoc()['count'];
    }

// کاڵا کەمەکان
$result = $conn->query("
    SELECT COUNT(*) as count
    FROM products p
    WHERE p.user_id = $userId
      AND COALESCE(
            (SELECT pu_primary.stock_quantity FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.stock_quantity FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          ) <= COALESCE(
            (SELECT pu_primary.min_stock FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.min_stock FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          )
");
if ($result) {
    $stats['low_stock_products'] = $result->fetch_assoc()['count'];
}

// کاڵا بەسەرچووەکان
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE user_id = $userId AND expiry_date IS NOT NULL AND expiry_date <= CURDATE()");
if ($result) {
    $stats['expired_products'] = $result->fetch_assoc()['count'];
}

// گشتی کەتەلۆگەکان
$result = $conn->query("SELECT COUNT(*) as count FROM categories WHERE user_id = $userId");
if ($result) {
    $stats['categories'] = $result->fetch_assoc()['count'];
}

// کاڵا بە کەتەلۆگ
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE user_id = $userId AND category_id IS NOT NULL AND category_id > 0");
if ($result) {
    $stats['products_with_category'] = $result->fetch_assoc()['count'];
}

// کاڵا بێ کەتەلۆگ  
$result = $conn->query("SELECT COUNT(*) as count FROM products WHERE user_id = $userId AND (category_id IS NULL OR category_id = 0)");
if ($result) {
    $stats['products_without_category'] = $result->fetch_assoc()['count'];
}

// فرۆشتنی ئەمڕۆ
$result = $conn->query("SELECT COUNT(*) as sales, IFNULL(SUM(final_amount), 0) as revenue FROM sales WHERE user_id = $userId AND DATE(sale_date) = CURDATE()");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['today_sales'] = $row['sales'];
    $stats['today_revenue'] = $row['revenue'];
}

// فرۆشتنی مانگانە
$result = $conn->query("SELECT COUNT(*) as sales, IFNULL(SUM(final_amount), 0) as revenue FROM sales WHERE user_id = $userId AND MONTH(sale_date) = MONTH(CURRENT_DATE()) AND YEAR(sale_date) = YEAR(CURRENT_DATE())");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['monthly_sales'] = $row['sales'];
    $stats['monthly_revenue'] = $row['revenue'];
}

// گشتی کڕیاران
$result = $conn->query("SELECT COUNT(*) as count FROM customers WHERE user_id = $userId");
if ($result) {
    $stats['total_customers'] = $result->fetch_assoc()['count'];
}

// کڕیارانی چالاک
$result = $conn->query("SELECT COUNT(*) as count FROM customers WHERE user_id = $userId AND status = 'active'");
if ($result) {
    $stats['active_customers'] = $result->fetch_assoc()['count'];
}

// قەرزە چالاکەکان و تەواوبووەکان
$result = $conn->query("SELECT 
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_debts,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_debts,
    COALESCE(SUM(CASE WHEN status = 'active' THEN remaining_amount ELSE 0 END), 0) as remaining_debt,
    COALESCE(SUM(paid_amount), 0) as received_payments
    FROM debts WHERE user_id = $userId");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['active_debts'] = $row['active_debts'];
    $stats['completed_debts'] = $row['completed_debts'];
    $stats['remaining_debt'] = $row['remaining_debt'];
    $stats['received_payments'] = $row['received_payments'];
}

// ئامارەکانی گەڕاندنەوە
$result = $conn->query("SELECT 
    COUNT(*) as total_returns,
    COUNT(CASE WHEN DATE(return_date) = CURDATE() THEN 1 END) as today_returns,
    COALESCE(SUM(CASE WHEN DATE(return_date) = CURDATE() THEN final_amount ELSE 0 END), 0) as today_return_amount,
    COUNT(CASE WHEN MONTH(return_date) = MONTH(CURDATE()) AND YEAR(return_date) = YEAR(CURDATE()) THEN 1 END) as monthly_returns,
    COALESCE(SUM(CASE WHEN MONTH(return_date) = MONTH(CURDATE()) AND YEAR(return_date) = YEAR(CURDATE()) THEN final_amount ELSE 0 END), 0) as monthly_return_amount
    FROM returns WHERE user_id = $userId");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['total_returns'] = $row['total_returns'];
    $stats['today_returns'] = $row['today_returns'];
    $stats['today_return_amount'] = $row['today_return_amount'];
    $stats['monthly_returns'] = $row['monthly_returns'];
    $stats['monthly_return_amount'] = $row['monthly_return_amount'];
}

// دوایین فرۆشتنەکان
$recentSales = [];
$result = $conn->query("SELECT s.*, 
    CASE 
        WHEN s.customer_name IS NOT NULL AND s.customer_name != '' THEN s.customer_name
        ELSE 'کڕیاری گشتی'
    END as display_customer_name,
    CASE 
        WHEN s.payment_method = 'cash' THEN 'نەقد'
        WHEN s.payment_method = 'debt' THEN 'قەرز'
        WHEN s.payment_method = 'card' THEN 'کارت'
        ELSE s.payment_method
    END as payment_method_kurdish,
    DATE_FORMAT(s.sale_date, '%Y/%m/%d %H:%i') as formatted_date
    FROM sales s 
    WHERE s.user_id = $userId 
    ORDER BY s.sale_date DESC 
    LIMIT 5");
if ($result) {
    $recentSales = $result->fetch_all(MYSQLI_ASSOC);
}

// کاڵا کەمەکان
$lowStockProducts = [];
$result = $conn->query("
    SELECT p.name,
           COALESCE(
                (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                 WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                 ORDER BY pu_primary.id ASC LIMIT 1),
                (SELECT pu_any.stock_quantity FROM product_units pu_any
                 WHERE pu_any.product_id = p.id
                 ORDER BY pu_any.id ASC LIMIT 1),
                0
           ) as stock_quantity,
           COALESCE(
                (SELECT pu_primary.min_stock FROM product_units pu_primary
                 WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                 ORDER BY pu_primary.id ASC LIMIT 1),
                (SELECT pu_any.min_stock FROM product_units pu_any
                 WHERE pu_any.product_id = p.id
                 ORDER BY pu_any.id ASC LIMIT 1),
                0
           ) as min_stock
    FROM products p
    WHERE p.user_id = $userId
      AND COALESCE(
            (SELECT pu_primary.stock_quantity FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.stock_quantity FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          ) <= COALESCE(
            (SELECT pu_primary.min_stock FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.min_stock FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          )
    ORDER BY stock_quantity ASC
    LIMIT 5
");
if ($result) {
    $lowStockProducts = $result->fetch_all(MYSQLI_ASSOC);
}

// دوایین کڕیاران
$recentCustomers = [];
$result = $conn->query("SELECT c.id, c.name, c.phone, 
    COALESCE((SELECT SUM(remaining_amount) FROM debts WHERE customer_id = c.id AND status = 'active'), 0) as debt
    FROM customers c 
    WHERE c.user_id = $userId 
    ORDER BY c.created_at DESC 
    LIMIT 5");
if ($result) {
    $recentCustomers = $result->fetch_all(MYSQLI_ASSOC);
}




// ڕێکەوتەکانی پێویست
$today = date('Y-m-d');
$currentMonth = date('Y-m');
$currentYear = date('Y');

// 1. ئاماری خەرجیەکان
// خەرجی ئەمڕۆ
$result = $conn->query("
    SELECT COUNT(*) as count, IFNULL(SUM(amount), 0) as amount 
    FROM expenses 
    WHERE user_id = $userId AND DATE(expense_date) = '$today'
");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['today_expenses'] = $row['count'];
    $stats['today_expenses_amount'] = $row['amount'];
}

// خەرجی مانگانە
$result = $conn->query("
    SELECT IFNULL(SUM(amount), 0) as amount 
    FROM expenses 
    WHERE user_id = $userId AND DATE_FORMAT(expense_date, '%Y-%m') = '$currentMonth'
");
if ($result) {
    $stats['monthly_expenses'] = $result->fetch_assoc()['amount'];
}

// خەرجی ساڵانە  
$result = $conn->query("
    SELECT IFNULL(SUM(amount), 0) as amount 
    FROM expenses 
    WHERE user_id = $userId AND YEAR(expense_date) = $currentYear
");
if ($result) {
    $stats['yearly_expenses'] = $result->fetch_assoc()['amount'];
}

// جۆرەکانی خەرجی (لە جەدوەڵی expense_types)
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM expense_types 
    WHERE user_id = $userId
");
if ($result) {
    $stats['total_expense_categories'] = $result->fetch_assoc()['count'];
}

// 2. ئاماری کەرەدیتی خەرجیەکان (expense_credits)
$result = $conn->query("
    SELECT COUNT(*) as count, IFNULL(SUM(remaining_amount), 0) as amount 
    FROM expense_credits 
    WHERE user_id = $userId AND status = 'active'
");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['active_credits'] = $row['count'];
    $stats['total_credit_amount'] = $row['amount'];
}

// 3. کاڵا تەواوبووەکان: هیچ یەکەیەک بڕی بەردەستی > 0 نییە (هەمان لۆجیکی index.php/POS)
$result = $conn->query("
    SELECT COUNT(*) as count
    FROM products p
    WHERE p.user_id = $userId
      AND NOT EXISTS (
            SELECT 1 FROM product_units pu_pos
            WHERE pu_pos.product_id = p.id AND pu_pos.stock_quantity > 0
          )
");
if ($result) {
    $stats['out_of_stock_products'] = $result->fetch_assoc()['count'];
}

// 4. کاڵا بەسەرچووەکان (expiry_date < ئەمڕۆ)
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM products 
    WHERE user_id = $userId AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
");
if ($result) {
    $stats['expired_products'] = $result->fetch_assoc()['count'];
}

// 5. کۆی مێژووی فرۆشتنەکان
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM sales 
    WHERE user_id = $userId
");
if ($result) {
    $stats['total_sales_history'] = $result->fetch_assoc()['count'];
}

// 6. ئاماری زیاتر بۆ کاڵاکان
// کاڵا کەمەکان (stock_quantity <= min_stock AND stock_quantity > 0)
$result = $conn->query("
    SELECT COUNT(*) as count
    FROM products p
    WHERE p.user_id = $userId
      AND COALESCE(
            (SELECT pu_primary.stock_quantity FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.stock_quantity FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          ) <= COALESCE(
            (SELECT pu_primary.min_stock FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.min_stock FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          )
      AND COALESCE(
            (SELECT pu_primary.stock_quantity FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.stock_quantity FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
          ) > 0
");
if ($result) {
    $stats['low_stock_products'] = $result->fetch_assoc()['count'];
}

// 7. ئاماری قەرزەکان - زیاتر ورد
// قەرزی چالاک
$result = $conn->query("
    SELECT COUNT(*) as count, IFNULL(SUM(remaining_amount), 0) as amount 
    FROM debts 
    WHERE user_id = $userId AND status = 'active'
");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['active_debts'] = $row['count'];
    $stats['remaining_debt'] = $row['amount'];
}

// قەرزی تەواوبوو
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM debts 
    WHERE user_id = $userId AND status = 'completed'
");
if ($result) {
    $stats['completed_debts'] = $result->fetch_assoc()['count'];
}

// پارە وەرگیراو لە قەرزەکان (لەم مانگەدا)
$result = $conn->query("
    SELECT IFNULL(SUM(dp.payment_amount), 0) as amount 
    FROM debt_payments dp
    INNER JOIN debts d ON dp.debt_id = d.id
    WHERE d.user_id = $userId 
    AND DATE_FORMAT(dp.payment_date, '%Y-%m') = '$currentMonth'
");
if ($result) {
    $stats['monthly_debt_payments'] = $result->fetch_assoc()['amount'];
}

// 8. ئاماری کڕیاران - زیاتر ورد
// کڕیاری چالاک (ئەوانەی قەرزیان هەیە)
$result = $conn->query("
    SELECT COUNT(DISTINCT customer_id) as count 
    FROM debts 
    WHERE user_id = $userId AND status = 'active' AND customer_id IS NOT NULL
");
if ($result) {
    $stats['customers_with_debt'] = $result->fetch_assoc()['count'];
}

// کڕیاری بێ قەرز
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM customers c
    WHERE c.user_id = $userId 
    AND NOT EXISTS (
        SELECT 1 FROM debts d 
        WHERE d.customer_id = c.id AND d.status = 'active'
    )
");
if ($result) {
    $stats['customers_no_debt'] = $result->fetch_assoc()['count'];
}

// 9. ئاماری فرۆشتن - زیاتر تێروتەسەل  
// فرۆشتنی هەفتەی ڕابردوو
$result = $conn->query("
    SELECT COUNT(*) as count, IFNULL(SUM(final_amount), 0) as amount
    FROM sales 
    WHERE user_id = $userId 
    AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
");
if ($result) {
    $row = $result->fetch_assoc();
    $stats['weekly_sales'] = $row['count'];
    $stats['weekly_revenue'] = $row['amount'];
}

// 10. ناوەندی نرخی فرۆشتن
$result = $conn->query("
    SELECT AVG(final_amount) as avg_sale 
    FROM sales 
    WHERE user_id = $userId AND final_amount > 0
");
    if ($result) {
        $stats['average_sale_amount'] = $result->fetch_assoc()['avg_sale'] ?? 0;
    }
} catch (Throwable $e) {
    error_log("Dashboard main stats error: " . $e->getMessage());
}



// ئامارەکانی کۆمپانیاکان (ماوەی قەرزی ژمێردراو وەک debts.php)
$companyStats = ['total_companies' => 0, 'active_companies' => 0, 'total_debt' => 0, 'avg_debt' => 0];
$debtStats = ['total_transactions' => 0, 'debt_transactions' => 0, 'payment_transactions' => 0, 'monthly_debt' => 0, 'monthly_payments' => 0];
$recentDebts = [];
$topDebtors = [];

try {
    if (function_exists('company_remaining_by_id_subquery_sql')) {
        $companyDebtDrSub = company_remaining_by_id_subquery_sql();
        $companyQuery = "SELECT 
            COUNT(*) as total_companies,
            COUNT(CASE WHEN c.status = 'active' THEN 1 END) as active_companies,
            COALESCE(SUM(dr.remaining), 0) as total_debt,
            COALESCE(AVG(dr.remaining), 0) as avg_debt
            FROM companies c
            LEFT JOIN $companyDebtDrSub dr ON dr.id = c.id
            WHERE c.user_id = ?";

        $companyStmt = $conn->prepare($companyQuery);
        if ($companyStmt) {
            $companyStmt->bind_param("ii", $userId, $userId);
            $companyStmt->execute();
            $companyStats = $companyStmt->get_result()->fetch_assoc() ?: $companyStats;
            $companyStmt->close();
        }
    }
} catch (Throwable $e) {
    error_log("Dashboard company stats error: " . $e->getMessage());
}

// ئامارەکانی قەرزەکان (ئەم مانگە)
try {
    $debtQuery = "SELECT 
        COUNT(*) as total_transactions,
        COUNT(CASE WHEN type = 'debt' THEN 1 END) as debt_transactions,
        COUNT(CASE WHEN type = 'payment' THEN 1 END) as payment_transactions,
        COALESCE(SUM(CASE WHEN type = 'debt' AND MONTH(date) = MONTH(CURDATE()) THEN amount ELSE 0 END), 0) as monthly_debt,
        COALESCE(SUM(CASE WHEN type = 'payment' AND MONTH(date) = MONTH(CURDATE()) THEN amount ELSE 0 END), 0) as monthly_payments
        FROM company_debts WHERE user_id = ?";

    $debtStmt = $conn->prepare($debtQuery);
    if ($debtStmt) {
        $debtStmt->bind_param("i", $userId);
        $debtStmt->execute();
        $debtStats = $debtStmt->get_result()->fetch_assoc() ?: $debtStats;
        $debtStmt->close();
    }
} catch (Throwable $e) {
    error_log("Dashboard debt stats error: " . $e->getMessage());
}

// وەرگرتنی دوایین مامەڵەکانی کۆمپانیاکان
try {
    $recentDebtsQuery = "SELECT cd.*, c.name as company_name 
                         FROM company_debts cd 
                         JOIN companies c ON cd.company_id = c.id 
                         WHERE cd.user_id = ? 
                         ORDER BY cd.created_at DESC 
                         LIMIT 5";

    $recentDebtsStmt = $conn->prepare($recentDebtsQuery);
    if ($recentDebtsStmt) {
        $recentDebtsStmt->bind_param("i", $userId);
        $recentDebtsStmt->execute();
        $recentDebts = $recentDebtsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recentDebtsStmt->close();
    }
} catch (Throwable $e) {
    error_log("Dashboard recent debts error: " . $e->getMessage());
}

// کۆمپانیا قەرزدارەکان (بەپێی ماوەی قەرزی ژمێردراو)
try {
    if (function_exists('company_remaining_by_id_subquery_sql')) {
        $companyDebtDrSub = company_remaining_by_id_subquery_sql();
        $debtorQuery = "SELECT c.name, dr.remaining AS debt_amount, c.phone 
                        FROM companies c
                        INNER JOIN $companyDebtDrSub dr ON dr.id = c.id
                        WHERE c.user_id = ? AND dr.remaining > 0 
                        ORDER BY dr.remaining DESC 
                        LIMIT 5";

        $debtorStmt = $conn->prepare($debtorQuery);
        if ($debtorStmt) {
            $debtorStmt->bind_param("ii", $userId, $userId);
            $debtorStmt->execute();
            $topDebtors = $debtorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $debtorStmt->close();
        }
    }
} catch (Throwable $e) {
    error_log("Dashboard top debtors error: " . $e->getMessage());
}

// ئاماری وەسڵە کڕدراوەکان
$purchaseStats = [
    'today_purchases' => 0,
    'today_amount' => 0,
    'monthly_count' => 0,
    'monthly_amount' => 0,
    'total_suppliers' => 0,
    'total_products_purchased' => 0,
    'total_purchase_amount' => 0,
    'avg_receipt_amount' => 0
];

try {
    // کڕینی ئەمڕۆ
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total
        FROM purchase_receipts 
        WHERE user_id = ? AND DATE(receipt_date) = CURDATE()
    ");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $todayPurchases = $stmt->get_result()->fetch_assoc();
        if ($todayPurchases) {
            $purchaseStats['today_purchases'] = $todayPurchases['count'];
            $purchaseStats['today_amount'] = $todayPurchases['total'];
        }
        $stmt->close();
    }

    // کڕینی مانگانە
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total
        FROM purchase_receipts 
        WHERE user_id = ? AND YEAR(receipt_date) = YEAR(CURDATE()) AND MONTH(receipt_date) = MONTH(CURDATE())
    ");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $monthlyPurchases = $stmt->get_result()->fetch_assoc();
        if ($monthlyPurchases) {
            $purchaseStats['monthly_count'] = $monthlyPurchases['count'];
            $purchaseStats['monthly_amount'] = $monthlyPurchases['total'];
        }
        $stmt->close();
    }

    // ژمارەی کۆمپانیاکان
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT company_id) as count FROM purchase_receipts WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $resSupplier = $stmt->get_result()->fetch_assoc();
        if ($resSupplier) $purchaseStats['total_suppliers'] = $resSupplier['count'];
        $stmt->close();
    }

    // کۆی کڕین
    $stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as total, COALESCE(AVG(final_amount), 0) as avg FROM purchase_receipts WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $totPurchases = $stmt->get_result()->fetch_assoc();
        if ($totPurchases) {
            $purchaseStats['total_purchase_amount'] = $totPurchases['total'];
            $purchaseStats['avg_receipt_amount'] = $totPurchases['avg'];
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log("Dashboard purchases stats error: " . $e->getMessage());
}

// ئاماری دەفتەری تێبینی
$stats['notebook_topics'] = 0;
$stats['notebook_entries'] = 0;
$stats['today_entries'] = 0;
$stats['favorite_entries'] = 0;

try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notebook_topics WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stats['notebook_topics'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notebook_entries WHERE user_id = ? AND is_archived = 0");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stats['notebook_entries'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log("Dashboard notebook stats error: " . $e->getMessage());
}



$csrf_token = Security::generateCSRFToken();

// ---------------------------------------------------------------------------
// کۆنتێکستی چەندین بزنس (Multi-Business) — تەنها بۆ خاوەنی ڕێکخراو کە ≥٢ بزنسی هەیە.
// بۆ بەکارهێنەری تاک-بزنس $bizSwitch = null دەمێنێتەوە و هیچ شتێک زیاد نابێت.
// ---------------------------------------------------------------------------
$bizSwitch = function_exists('getBusinessSwitchContext') ? getBusinessSwitchContext() : null;
$bizHasMulti = ($bizSwitch !== null && count($bizSwitch['businesses']) >= 2);
$bizActiveId = $bizHasMulti ? (int)$bizSwitch['active_id'] : 0;
$bizActiveName = '';
if ($bizHasMulti) {
    foreach ($bizSwitch['businesses'] as $b) {
        if ((int)$b['id'] === $bizActiveId) { $bizActiveName = $b['business_name']; break; }
    }
}
// گرادیێنتی avatar بەپێی ئیندێکس — دووبارە بەکاردێتەوە لە navbar و پانێڵدا.
$bizGradients = [
    'linear-gradient(135deg,#0d6efd,#3b82f6)',
    'linear-gradient(135deg,#059669,#10b981)',
    'linear-gradient(135deg,#d97706,#f59e0b)',
    'linear-gradient(135deg,#7c3aed,#a855f7)',
    'linear-gradient(135deg,#0891b2,#06b6d4)',
    'linear-gradient(135deg,#db2777,#ec4899)',
];
$bizFirstChar = static function ($name) {
    $name = trim((string)$name);
    if ($name === '') return '؟';
    return mb_substr($name, 0, 1, 'UTF-8');
};
$bizIsUsable = static function ($b) {
    return (($b['status'] ?? '') === 'approved')
        && (empty($b['expiration_date']) || strtotime($b['expiration_date']) >= time());
};
$bizOwnerId = $bizHasMulti ? (int)$bizSwitch['organization']['owner_user_id'] : 0;
$productsAlertCount = (int)($stats['low_stock_products'] ?? 0)
    + (int)($stats['out_of_stock_products'] ?? 0)
    + (int)($stats['expired_products'] ?? 0);

$renderDashTile = static function (
    string $href,
    string $sectionClass,
    string $icon,
    string $title,
    ?int $badge = null,
    ?string $desc = null
): string {
    ob_start();
    ?>
    <a href="<?php echo $href; ?>" class="dash-tile <?php echo htmlspecialchars($sectionClass, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($badge !== null && $badge > 0): ?>
            <span class="dash-tile-badge"><?php echo $badge > 99 ? '99+' : (int) $badge; ?></span>
        <?php endif; ?>
        <div class="dash-tile-icon"><i class="bi <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i></div>
        <div class="dash-tile-body">
            <h6><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h6>
            <?php if ($desc): ?>
                <span class="dash-tile-desc"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>
        <i class="bi bi-arrow-left dash-tile-arrow"></i>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>داشبۆرد - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#4f46e5">
    <?php require_once dirname(__DIR__, 2) . '/includes/pwa_head.php'; ?>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/dashboard/dashboard-dark.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/dashboard.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/dashboard-page.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/dashboard-fancy.css'); ?>" rel="stylesheet">
</head>
<body class="dashboard-page">

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary kasher-navbar sticky-top">
        <div class="container-fluid">
            <div class="navbar-nav">
            <a class="nav-link large-nav-btn" href="<?php echo url('user/pos/index.php'); ?>">
                    <i class="bi bi-cart"></i> فرۆشتن
                </a>
                  
            <a class="nav-link large-nav-btn" href="<?php echo url('user/products/index.php'); ?>">
                    <i class="bi bi-box-seam"></i> کاڵاکان
                </a>
               
            </div>
            
            <a class="navbar-brand mx-auto" href="<?php echo url('user/dashboard/index.php'); ?>">
                <i class="bi bi-shop"></i>
                <?php echo htmlspecialchars($currentUser['business_name']); ?>
            </a>

            <div class="d-flex align-items-center gap-2">
                <?php if ($bizHasMulti): ?>
                <!-- گۆڕەری بزنس (Multi-Business Switcher) -->
                <div class="dropdown biz-switch">
                    <button class="dropdown-toggle" type="button" id="bizSwitchNav"
                            data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-shop-window"></i>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($bizActiveName); ?></span>
                        <i class="bi bi-chevron-down small"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="bizSwitchNav">
                        <li class="dropdown-header"><i class="bi bi-diagram-3"></i> بزنسەکان</li>
                        <?php foreach ($bizSwitch['businesses'] as $i => $b): ?>
                            <?php
                                $isActive = ((int)$b['id'] === $bizActiveId);
                                $usable = $bizIsUsable($b);
                                $grad = $bizGradients[$i % count($bizGradients)];
                            ?>
                            <li>
                                <form method="POST" action="<?php echo url('user/switch_business.php'); ?>" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="business_id" value="<?php echo (int)$b['id']; ?>">
                                    <button type="submit" class="dd-item<?php echo $isActive ? ' active' : ''; ?>"<?php echo (!$usable && !$isActive) ? ' disabled' : ''; ?>>
                                        <span class="d-flex align-items-center gap-2">
                                            <span class="dd-ava" style="background:<?php echo $grad; ?>"><?php echo htmlspecialchars($bizFirstChar($b['business_name'])); ?></span>
                                            <?php echo htmlspecialchars($b['business_name']); ?>
                                        </span>
                                        <?php if ($isActive): ?>
                                            <i class="bi bi-check-circle-fill text-primary"></i>
                                        <?php elseif (!$usable): ?>
                                            <small class="text-danger">ناچالاک</small>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                        <li><hr class="my-1"></li>
                        <li>
                            <a class="report-link" href="<?php echo url('user/reports/businesses_overview.php'); ?>">
                                <i class="bi bi-bar-chart-line"></i> ڕاپۆرتی گشتی هەموو بزنسەکان
                            </a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>

                <a class="nav-link text-white" href="<?php echo url('user/auth/logout.php'); ?>">
                    <i class="bi bi-box-arrow-right"></i> دەرچوون
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- Welcome Hero -->
        <div class="dash-hero">
            <div class="dash-hero-main">
                <h1 class="dash-hero-greeting">بەخێرهاتیت، <?php echo htmlspecialchars($currentUser['business_name']); ?></h1>
                <?php if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub'): ?>
                    <p class="dash-hero-sub user-badge">
                        <i class="bi bi-person-badge"></i>
                        <?php echo htmlspecialchars($currentUser['full_name']); ?>
                    </p>
                <?php else: ?>
                    <p class="dash-hero-sub">بەڕێوەبردنی فرۆشتن، کاڵا، کڕیار و زیاتر — هەموو بەشەکان لێرەن.</p>
                <?php endif; ?>
                <div class="dash-hero-pills">
                    <span class="dash-hero-pill"><i class="bi bi-calendar3"></i> <?php echo date('Y/m/d'); ?></span>
                    <span class="dash-hero-pill"><i class="bi bi-clock"></i> <?php echo date('H:i'); ?></span>
                    <?php if (is_array($packageInfo) && !empty($packageInfo['name'])): ?>
                        <span class="dash-hero-pill"><i class="bi bi-patch-check"></i> <?php echo htmlspecialchars($packageInfo['name']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="dash-hero-actions">
                    <?php if ($canViewSalesCard): ?>
                    <a href="<?php echo url('user/pos/index.php'); ?>" class="dash-hero-btn primary">
                        <i class="bi bi-cart-check"></i> فرۆشتنی خێرا
                    </a>
                    <?php endif; ?>
                    <?php if ($canViewProductsCard): ?>
                    <a href="<?php echo url('user/products/index.php'); ?>" class="dash-hero-btn secondary">
                        <i class="bi bi-box-seam"></i> کاڵاکان
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($canViewSalesCard || $canViewProductsCard || $canViewCustomersCard): ?>
            <div class="dash-hero-stats">
                <?php if ($canViewSalesCard): ?>
                <div class="dash-stat sales">
                    <div class="dash-stat-icon"><i class="bi bi-cash-stack"></i></div>
                    <div class="dash-stat-value"><?php echo formatMoney($stats['today_revenue']); ?></div>
                    <div class="dash-stat-label">داهاتی ئەمڕۆ</div>
                </div>
                <div class="dash-stat month">
                    <div class="dash-stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="dash-stat-value"><?php echo formatMoney($stats['monthly_revenue']); ?></div>
                    <div class="dash-stat-label">داهاتی مانگ</div>
                </div>
                <?php endif; ?>
                <?php if ($canViewProductsCard): ?>
                <div class="dash-stat products">
                    <div class="dash-stat-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="dash-stat-value"><?php echo number_format((int) $stats['total_products']); ?></div>
                    <div class="dash-stat-label">کاڵا<?php if ($productsAlertCount > 0): ?> · <?php echo (int) $productsAlertCount; ?> ئاگاداری<?php endif; ?></div>
                </div>
                <?php endif; ?>
                <?php if ($canViewCustomersCard): ?>
                <div class="dash-stat customers">
                    <div class="dash-stat-icon"><i class="bi bi-people"></i></div>
                    <div class="dash-stat-value"><?php echo number_format((int) $stats['total_customers']); ?></div>
                    <div class="dash-stat-label">کڕیار</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($bizHasMulti): ?>
        <!-- ============ پانێڵی بزنسەکان (Multi-Business Panel) ============ -->
        <div class="biz-panel mb-4">
            <div class="biz-panel-head">
                <h5>
                    <i class="bi bi-diagram-3"></i> بزنسەکانم
                    <span class="badge bg-light text-dark ms-1"><?php echo count($bizSwitch['businesses']); ?></span>
                </h5>
                <a href="<?php echo url('user/reports/businesses_overview.php'); ?>" class="btn btn-report">
                    <i class="bi bi-bar-chart-line"></i> ڕاپۆرتی گشتی هەموو بزنسەکان
                </a>
            </div>
            <div class="biz-panel-body">
                <?php foreach ($bizSwitch['businesses'] as $i => $b): ?>
                    <?php
                        $isActive = ((int)$b['id'] === $bizActiveId);
                        $usable = $bizIsUsable($b);
                        $grad = $bizGradients[$i % count($bizGradients)];
                        $isOwnerBiz = ((int)$b['id'] === $bizOwnerId);
                    ?>
                    <?php if ($isActive): ?>
                        <div class="biz-card is-active">
                            <span class="biz-badge">چالاک</span>
                            <div class="biz-ava" style="background:<?php echo $grad; ?>"><?php echo htmlspecialchars($bizFirstChar($b['business_name'])); ?></div>
                            <h6><?php echo htmlspecialchars($b['business_name']); ?></h6>
                            <div class="muted">
                                <?php if ($isOwnerBiz): ?><i class="bi bi-star-fill text-warning"></i> بزنسی خاوەن<?php else: ?><i class="bi bi-shop"></i> بزنس<?php endif; ?>
                            </div>
                            <div class="go"><i class="bi bi-check-circle-fill"></i> ئێستا لێرەیت</div>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="<?php echo url('user/switch_business.php'); ?>" class="m-0 biz-card-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="business_id" value="<?php echo (int)$b['id']; ?>">
                            <button type="submit" class="biz-card<?php echo !$usable ? ' is-disabled' : ''; ?>"<?php echo !$usable ? ' disabled' : ''; ?>>
                                <div class="biz-ava" style="background:<?php echo $grad; ?>"><?php echo htmlspecialchars($bizFirstChar($b['business_name'])); ?></div>
                                <h6><?php echo htmlspecialchars($b['business_name']); ?></h6>
                                <div class="muted">
                                    <?php if ($isOwnerBiz): ?><i class="bi bi-star-fill text-warning"></i> بزنسی خاوەن<?php elseif (!$usable): ?><span class="text-danger">ناچالاک</span><?php else: ?><i class="bi bi-shop"></i> بزنس<?php endif; ?>
                                </div>
                                <?php if ($usable): ?>
                                    <div class="go"><i class="bi bi-arrow-left-circle"></i> بگۆڕە بۆ ئەمە</div>
                                <?php endif; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showExpirationWarning && $expirationFormatted): ?>
        <div class="row mb-3">
            <div class="col-12">
                <div class="account-expiration-card">
                    <div class="account-expiration-icon">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                    <div class="account-expiration-content">
                        <h5>ئاگادارکردنەوەی نوێکردنەوەی ئەکاونت</h5>
                        <p>
                            ئەکاونتەکەت لە
                            <span class="expiration-date"><?php echo htmlspecialchars($expirationFormatted); ?></span>
                            پێویستی بە نوێکردنەوەیە، پێش ئەوەی بوەستێت پەیوەندی بە بەڕێوبەر بکە تا نوێ بکرێتەوە بۆ ئەوەی بەردەوام بیت لە بەکارهێنانی خزمەتگوزارییەکان بەبێ پچڕان.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PWA Install Banner -->
        <div class="row mb-3">
            <div class="col-12">
                <div id="pwaInstallBanner" class="pwa-install-banner" hidden>
                    <div class="pwa-install-content">
                        <img src="<?php echo asset('images/pwa/icon-192.png'); ?>" alt="<?php echo clean(SITE_NAME); ?>" class="pwa-install-logo">
                        <div class="pwa-install-text">
                            <strong>دامەزراندنی ئەپ لەسەر شاشە</strong>
                            <p>NexoraCore لەسەر شاشەی مۆبایل یان کۆمپیوتەر دابمەزرێنە بۆ دەستپێگەیشتنی خێرا</p>
                        </div>
                        <div class="pwa-install-actions">
                            <button type="button" id="pwaInstallBtn" class="btn btn-light btn-sm">
                                <i class="bi bi-download"></i> دامەزراندن
                            </button>
                            <button type="button" id="pwaInstallDismiss" class="btn btn-link btn-sm">دواتر</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PWA iOS Install Modal -->
        <div class="pwa-ios-modal" id="pwaIosModal">
            <div class="pwa-ios-modal-content">
                <div class="pwa-ios-modal-header">
                    <button type="button" class="modal-close-btn" onclick="closePwaIosModal()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <img src="<?php echo asset('images/pwa/icon-192.png'); ?>" alt="<?php echo clean(SITE_NAME); ?>">
                    <h4>دامەزراندن لەسەر شاشەی iPhone/iPad</h4>
                </div>
                <div class="pwa-ios-modal-body">
                    <div class="pwa-ios-steps">
                        <ol>
                            <li>دوگمەی <strong>Share</strong> <i class="bi bi-box-arrow-up"></i> لە خوارەوەی Safari دابگرە</li>
                            <li><strong>Add to Home Screen</strong> هەڵبژێرە</li>
                            <li>دوگمەی <strong>Add</strong> دابگرە — ئایکۆنەکە لۆگۆی NexoraCore دەبێت</li>
                        </ol>
                    </div>
                    <button type="button" class="btn btn-primary w-100" onclick="closePwaIosModal()">
                        <i class="bi bi-check-circle"></i> تێگەیشتم
                    </button>
                </div>
            </div>
        </div>
        <!-- Support Banner -->
        <div class="dash-banner support" onclick="openTelegramModal()" role="button" tabindex="0" onkeydown="if(event.key==='Enter')openTelegramModal()">
            <div class="dash-banner-inner">
                <div class="dash-banner-icon"><i class="bi bi-headset"></i></div>
                <div class="dash-banner-text">
                    <strong>پەیوەندیکردن و پشتگیری</strong>
                    <p>بۆ هەر پێشنیار، کێشە یان پرسیارێک کرتە لێرە بکە</p>
                </div>
                <i class="bi bi-chevron-left dash-banner-arrow"></i>
            </div>
        </div>

        <!-- Telegram Modal -->
        <div class="telegram-modal" id="telegramModal">
            <div class="telegram-modal-content">
                <div class="telegram-modal-header">
                    <i class="bi bi-telegram"></i>
                    <h4>پەیوەندیکردن لە ڕێگەی تیلیگرامەوە</h4>
                    <button class="modal-close-btn" onclick="closeTelegramModal()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="telegram-modal-body">
                    <div class="telegram-message">
                        <i class="bi bi-chat-dots-fill"></i>
                        <p>هەر کێشەیەک یان کەم و کورتیەک یان پێشنیازێک یان تایبەتمەندیەکتان ویست لە تیلیگرام بۆمان بنێرن</p>
                    </div>
                    <div class="telegram-buttons">
                        <a href="https://t.me/itz_levi0" target="_blank" class="btn-telegram">
                            <i class="bi bi-telegram"></i>
                            پەیوەندیکردن لە تیلیگرام
                        </a>
                        <button class="btn-close-modal" onclick="closeTelegramModal()">
                            <i class="bi bi-x-circle"></i>
                            داخستن
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Main sections — bento grid -->
        <div class="dash-section-head">
            <h5><i class="bi bi-grid-3x3-gap-fill"></i> بەشە سەرەکییەکان</h5>
        </div>
        <div class="dash-bento-grid">
            <?php if ($canViewSalesCard): ?>
                <?php echo $renderDashTile(url('user/pos/main.php'), 'section-sales', 'bi-cash-coin', 'فرۆشتن', null, 'POS · وەسڵ · گەڕاندنەوە'); ?>
            <?php endif; ?>

            <?php if ($canViewProductsCard): ?>
                <?php echo $renderDashTile(url('user/products/main.php'), 'section-products', 'bi-box-seam', 'کاڵاکان', $productsAlertCount > 0 ? $productsAlertCount : null, 'کاڵا · کۆگا · کەتەلۆگ'); ?>
            <?php endif; ?>

            <?php if ($canViewCustomersCard): ?>
                <?php echo $renderDashTile(url('user/customers/main.php'), 'section-customers', 'bi-people-fill', 'کڕیاران', null, 'کڕیار · قەرز · پارەدان'); ?>
            <?php endif; ?>

            <?php if ($canViewExpensesCard): ?>
                <?php echo $renderDashTile(url('user/expenses/main.php'), 'section-expenses', 'bi-wallet2', 'خەرجییەکان', null, 'خەرجی · جۆر · ئامار'); ?>
            <?php endif; ?>

            <?php if ($canViewWalletsCard): ?>
                <?php echo $renderDashTile(url('user/wallets/main.php'), 'section-wallets', 'bi-safe2', 'قاسەکان', null, 'قاسە · گواستنەوە'); ?>
            <?php endif; ?>

            <?php if ($canViewReportsCard): ?>
                <?php echo $renderDashTile(url('user/reports/main.php'), 'section-reports', 'bi-clipboard-data', 'ڕاپۆرت', null, 'فرۆشتن · قازانج · ئامار'); ?>
            <?php endif; ?>

            <?php if ($canViewCompaniesCard): ?>
                <?php echo $renderDashTile(url('user/companies/main.php'), 'section-companies', 'bi-building', 'کۆمپانیاکان', null, 'کڕین · دابینکەر'); ?>
            <?php endif; ?>

            <?php if ($canViewNotebooksCard): ?>
                <?php echo $renderDashTile(url('user/notebooks/index.php'), 'section-notebooks', 'bi-journal-text', 'دەفتەری تێبینی', null, 'تێبینی · یادەوەری'); ?>
            <?php endif; ?>

            <?php if ($canViewSettingsCard): ?>
                <?php echo $renderDashTile(url('user/settings/main.php'), 'section-settings', 'bi-gear', 'ڕێکخستنەکان', null, 'سیستەم · چاپ · ڕێکخستن'); ?>
            <?php endif; ?>

            <?php if ($canViewEmployeesCard): ?>
                <?php echo $renderDashTile(url('user/employees/main.php'), 'section-sub-users', 'bi-person-badge', 'کارمەندان', null, 'دەسەڵات · بەکارهێنەر'); ?>
            <?php endif; ?>

            <?php if ($canViewMedicalStaffCard): ?>
                <?php echo $renderDashTile(url('user/medical_staff/main.php'), 'section-medical-staff', 'bi-hospital', 'بەڕێوەبردنی پزیشکی', null, 'پزیشک · نۆرە'); ?>
            <?php endif; ?>

            <?php if ($canViewLabStaffCard): ?>
                <?php echo $renderDashTile(url('user/lab_staff/main.php'), 'section-lab-staff', 'bi-flask', 'تاقیگە', null, 'ئەنجام · تاقیکردنەوە'); ?>
            <?php endif; ?>

            <?php if ($canViewCosmeticStaffCard): ?>
                <?php echo $renderDashTile(url('user/cosmetic_staff/main.php'), 'section-cosmetic-staff', 'bi-flower1', 'بەڕێوەبردنی جوانکاری', null, 'خزمەت · نۆرە'); ?>
            <?php endif; ?>

            <?php if ($canViewChatbotCard): ?>
                <?php echo $renderDashTile(url('user/chatbot/index.php'), 'section-kashery-ai', 'bi-robot', 'NexoraCore AI', null, 'یاریدەدەر · پرسیار'); ?>
            <?php endif; ?>

            <?php echo $renderDashTile(url('user/website/'), 'section-website', 'bi-globe2', 'وێبسایت', null, 'فرۆشگای ئۆنلاین'); ?>
            <?php echo $renderDashTile(url('user/aboutsystem/main.php'), 'section-about-us', 'bi-info-circle', 'دەربارەی ئێمە و سیستم', null, 'وەشان · پشتگیری'); ?>
        </div>



        <?php
        // Show message if no ABAC-governed dashboard section is available
        if (!$hasAnyRestrictedDashboardCard): ?>
                        <div class="dash-empty-state">
                <i class="bi bi-shield-lock"></i>
                <h4>هیچ بەشێک بەردەست نییە</h4>
                <p class="text-muted mb-0">
                    پاکێجی ئێستات تەنها دەسەڵاتی بینینی داشبۆردت پێداوە.
                    بۆ دەستپێگەیشتن بە بەشەکانی تر، پەیوەندی بە بەڕێوەبەرەوە بکە.
                </p>
            </div>
        <?php endif; ?>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Telegram Modal Script -->
    <script>
        function openTelegramModal() {
            const modal = document.getElementById('telegramModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        }

        function closeTelegramModal() {
            const modal = document.getElementById('telegramModal');
            modal.classList.remove('show');
            document.body.style.overflow = ''; // Restore scrolling
        }

        // Close modal when clicking outside the content
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('telegramModal');
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeTelegramModal();
                }
            });

            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeTelegramModal();
                }
            });
        });
    </script>

    <script src="<?php echo asset('js/pwa-install.js'); ?>" defer></script>
    
    <!-- Auto refresh every 5 minutes (تەنها کاتێک هێڵ هەیە) -->
    <script>
        setTimeout(function() {
            if (navigator.onLine) {
                window.location.reload();
            }
        }, 300000); // 5 minutes
    </script>

</body>
</html>