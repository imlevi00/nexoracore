<?php
/**
 * بەڕێوەبردنی بەکارهێنەرانی لاوەکی - user/employees/manage.php
 */
 
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'employees.view', [
    'route' => '/user/employees/manage.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireEmployeesManageAccess();
$userId = $currentUser['id'];

$permissionCatalog = getPermissionCatalog();
$legacySectionMap = getSectionToPermissionMap();
$permissionModuleLabels = [
    'dashboard' => 'داشبۆرد',
    'pos' => 'فرۆشتن (POS)',
    'products' => 'بەرهەمەکان',
    'inventory' => 'کۆگا',
    'categories' => 'پۆلەکان',
    'returns' => 'گەڕاندنەوەکان',
    'customers' => 'کڕیارەکان',
    'debts' => 'قەرزەکان',
    'customer_info' => 'زانیاری کڕیار',
    'reports' => 'ڕاپۆرتەکان',
    'profits' => 'قازانجەکان',
    'expenses' => 'خەرجییەکان',
    'expense_credits' => 'قەرزی خەرجی',
    'expense_stats' => 'ئامارەکانی خەرجی',
    'notebooks' => 'تێبینییەکان',
    'settings' => 'ڕێکخستنەکان',
    'backup' => 'پاشەکەوت',
    'telegram' => 'تیلیگرام',
    'dollar_price' => 'نرخی دۆلار',
    'employees' => 'کارمەندەکان',
    'companies' => 'کۆمپانیاکان',
    'purchases' => 'کڕینەکان',
];

// وەرگرتنی زۆرترین ژمارەی کارمەندان بەپێی پاکێج
$maxEmployees = getMaxEmployeesForPackage();

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submittedPolicyJson = $_POST['abac_policies_json'] ?? '[]';
    $submittedPolicies = json_decode($submittedPolicyJson, true);
    if (!is_array($submittedPolicies)) {
        $submittedPolicies = [];
    }
    
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }
    
    elseif ($action === 'create') {
        enforceAuthorizationOrDeny($currentUser, 'employees.create', [
            'route' => '/user/employees/manage.php',
            'request_method' => 'POST'
        ], 'redirect');
        // تاقیکردنی ژمارەی بەکارهێنەرانی لاوەکی - سنوور بەپێی پاکێج
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM sub_users WHERE main_user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $currentSubUsersCount = $row['count'];
        $stmt->close();

        if ($maxEmployees <= 0) {
            $error = 'ئەم خزمەتگوزارییە بۆ پاکێجەکەت بەردەست نییە. پاکێجەکەت بەرزبکەرەوە بۆ بەکارهێنانی کارمەندان.';
        } elseif ($currentSubUsersCount >= $maxEmployees) {
            $error = 'ناتوانیت زیاتر لە ' . $maxEmployees . ' کارمەند دروست بکەیت. پاکێجەکەت بەرزبکەرەوە بۆ زیادکردنی کارمەندی زیاتر.';
        } else {
            $username = cleanInput($_POST['username'] ?? '');
            $email = cleanInput($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $full_name = cleanInput($_POST['full_name'] ?? '');
            $permissions = $_POST['permissions'] ?? [];

            if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
                $error = 'تکایە هەموو خانە پێویستەکان پڕبکەرەوە';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'ئیمەیڵەکە دروست نییە';
            } elseif (strlen($password) < 6) {
                $error = 'پاسۆرد دەبێت لانیکەم ٦ پیت بێت';
            } else {
                // Check if username or email already exists
                $stmt = $conn->prepare("SELECT id FROM sub_users WHERE username = ? OR email = ?");
                $stmt->bind_param("ss", $username, $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $error = 'ناوی بەکارهێنەر یان ئیمەیڵ پێشتر بەکارهاتووە';
                } else {
                    // Create sub-user
                    $hashedPassword = Security::hashPassword($password);
                    $permissionsJson = json_encode($permissions);

                    $stmt = $conn->prepare("INSERT INTO sub_users (main_user_id, username, email, password, full_name, permissions) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssss", $userId, $username, $email, $hashedPassword, $full_name, $permissionsJson);

                    if ($stmt->execute()) {
                        $newSubUserId = (int)$conn->insert_id;
                        saveUserAbacPolicies($userId, 'sub_user', $newSubUserId, $submittedPolicies);
                        invalidateAuthorizationCacheForUser($newSubUserId, 'sub_user');
                        $success = 'بەکارهێنەری لاوەکی بە سەرکەوتوویی دروستکرا';
                        writeLog("Sub-user created: $username by main user: {$currentUser['email']}");
                    } else {
                        $error = 'هەڵەیەک ڕوویدا لە دروستکردنی بەکارهێنەری لاوەکی';
                    }
                }
                $stmt->close();
            }
        }
    }
    
    elseif ($action === 'update') {
        enforceAuthorizationOrDeny($currentUser, 'employees.update', [
            'route' => '/user/employees/manage.php',
            'request_method' => 'POST'
        ], 'redirect');
        $subUserId = (int)($_POST['sub_user_id'] ?? 0);
        $username = cleanInput($_POST['username'] ?? '');
        $email = cleanInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $full_name = cleanInput($_POST['full_name'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($username) || empty($email) || empty($full_name)) {
            $error = 'تکایە هەموو خانە پێویستەکان پڕبکەرەوە';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'ئیمەیڵەکە دروست نییە';
        } else {
            // Check if sub-user belongs to current user
            $stmt = $conn->prepare("SELECT id FROM sub_users WHERE id = ? AND main_user_id = ?");
            $stmt->bind_param("ii", $subUserId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $error = 'بەکارهێنەری لاوەکی نەدۆزرایەوە';
            } else {
                // Check if username or email already exists (excluding current user)
                $stmt = $conn->prepare("SELECT id FROM sub_users WHERE (username = ? OR email = ?) AND id != ?");
                $stmt->bind_param("ssi", $username, $email, $subUserId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $error = 'ناوی بەکارهێنەر یان ئیمەیڵ پێشتر بەکارهاتووە';
                } else {
                    $permissionsJson = json_encode($permissions);
                    
                    if (!empty($password)) {
                        if (strlen($password) < 6) {
                            $error = 'پاسۆرد دەبێت لانیکەم ٦ پیت بێت';
                        } else {
                            $hashedPassword = Security::hashPassword($password);
                            $stmt = $conn->prepare("UPDATE sub_users SET username = ?, email = ?, password = ?, full_name = ?, permissions = ?, is_active = ? WHERE id = ?");
                            $stmt->bind_param("sssssii", $username, $email, $hashedPassword, $full_name, $permissionsJson, $is_active, $subUserId);
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE sub_users SET username = ?, email = ?, full_name = ?, permissions = ?, is_active = ? WHERE id = ?");
                        $stmt->bind_param("ssssii", $username, $email, $full_name, $permissionsJson, $is_active, $subUserId);
                    }
                    
                    if (isset($stmt) && $stmt->execute()) {
                        saveUserAbacPolicies($userId, 'sub_user', $subUserId, $submittedPolicies);
                        invalidateAuthorizationCacheForUser($subUserId, 'sub_user');
                        $success = 'بەکارهێنەری لاوەکی بە سەرکەوتوویی نوێکرایەوە';
                        writeLog("Sub-user updated: $username by main user: {$currentUser['email']}");
                    } else {
                        $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی بەکارهێنەری لاوەکی';
                    }
                }
            }
            $stmt->close();
        }
    }
    
    elseif ($action === 'delete') {
        enforceAuthorizationOrDeny($currentUser, 'employees.delete', [
            'route' => '/user/employees/manage.php',
            'request_method' => 'POST'
        ], 'redirect');
        $subUserId = (int)($_POST['sub_user_id'] ?? 0);
        
        // Check if sub-user belongs to current user
        $stmt = $conn->prepare("SELECT username FROM sub_users WHERE id = ? AND main_user_id = ?");
        $stmt->bind_param("ii", $subUserId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = 'بەکارهێنەری لاوەکی نەدۆزرایەوە';
        } else {
            $subUser = $result->fetch_assoc();
            
            // Delete sub-user
            $stmt = $conn->prepare("DELETE FROM sub_users WHERE id = ? AND main_user_id = ?");
            $stmt->bind_param("ii", $subUserId, $userId);
            
            if ($stmt->execute()) {
                invalidateAuthorizationCacheForUser($subUserId, 'sub_user');
                $success = 'بەکارهێنەری لاوەکی بە سەرکەوتوویی سڕایەوە';
                writeLog("Sub-user deleted: {$subUser['username']} by main user: {$currentUser['email']}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە سڕینەوەی بەکارهێنەری لاوەکی';
            }
        }
        $stmt->close();
    }
}

// Get sub-users list
$subUsers = [];
$stmt = $conn->prepare("SELECT id, username, email, full_name, permissions, is_active, last_login, created_at FROM sub_users WHERE main_user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['permissions'] = json_decode($row['permissions'], true);
    $row['abac_policies'] = getUserAbacPolicies((int)$row['id'], 'sub_user', $userId);
    $subUsers[] = $row;
}
$stmt->close();

// Get sub-user statistics
$stats = [
    'total_sub_users' => count($subUsers),
    'active_sub_users' => count(array_filter($subUsers, function($user) { return $user['is_active']; })),
    'total_sales_by_sub_users' => 0,
    'today_sales_by_sub_users' => 0,
    'total_revenue_by_sub_users' => 0,
    'today_revenue_by_sub_users' => 0
];

// Get sales statistics for sub-users
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_sales, 
    IFNULL(SUM(CASE WHEN DATE(sale_date) = CURDATE() THEN 1 ELSE 0 END), 0) as today_sales,
    IFNULL(SUM(final_amount), 0) as total_revenue,
    IFNULL(SUM(CASE WHEN DATE(sale_date) = CURDATE() THEN final_amount ELSE 0 END), 0) as today_revenue
    FROM sales WHERE user_id = ? AND user_type = 'sub'");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['total_sales_by_sub_users'] = $row['total_sales'];
    $stats['today_sales_by_sub_users'] = $row['today_sales'];
    $stats['total_revenue_by_sub_users'] = $row['total_revenue'];
    $stats['today_revenue_by_sub_users'] = $row['today_revenue'];
}
$stmt->close();

// Get detailed statistics for each sub-user
foreach ($subUsers as &$subUser) {
    $stmt = $conn->prepare("SELECT 
        COUNT(*) as sales_count,
        IFNULL(SUM(final_amount), 0) as total_revenue,
        IFNULL(SUM(CASE WHEN DATE(sale_date) = CURDATE() THEN 1 ELSE 0 END), 0) as today_sales,
        IFNULL(SUM(CASE WHEN DATE(sale_date) = CURDATE() THEN final_amount ELSE 0 END), 0) as today_revenue
        FROM sales WHERE user_id = ? AND sub_user_id = ?");
    $stmt->bind_param("ii", $userId, $subUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $subUser['sales_count'] = $row['sales_count'];
        $subUser['total_revenue'] = $row['total_revenue'];
        $subUser['today_sales'] = $row['today_sales'];
        $subUser['today_revenue'] = $row['today_revenue'];
    } else {
        $subUser['sales_count'] = 0;
        $subUser['total_revenue'] = 0;
        $subUser['today_sales'] = 0;
        $subUser['today_revenue'] = 0;
    }
    $stmt->close();
}
unset($subUser);

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوەبردنی بەکارهێنەرانی لاوەکی - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    <link href="<?php echo asset('css/employees/employees-dark.css'); ?>" rel="stylesheet">
    
    <style>
        .permission-checkbox {
            margin: 5px 0;
        }
        .sub-user-card {
            transition: all 0.3s ease;
        }
        .sub-user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            color: #fff !important;
            border: 0 !important;
            border-radius: 15px;
        }
        .stats-card .card-body,
        .stats-card .card-body h3,
        .stats-card .card-body p,
        .stats-card .card-body i {
            color: #fff !important;
        }

    </style>
</head>
<body class="employees-module-page employees-manage-page bg-light">
    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="mb-1">
                    <i class="bi bi-people-fill text-primary"></i>
                    بەڕێوەبردنی بەکارهێنەرانی لاوەکی
                </h2>
                <p class="text-muted">بەڕێوەبردنی بەکارهێنەرانی لاوەکی و دەسەڵاتەکانیان</p>
            </div>
        </div>

        <?php if ($maxEmployees > 0 && $stats['total_sub_users'] > $maxEmployees): ?>
        <!-- Warning: Employee count exceeds limit -->
        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div>
                <strong>ئاگاداری گرنگ:</strong> ژمارەی کارمەندەکانت (<?php echo $stats['total_sub_users']; ?>) زیاترە لەوەی لە پاکێجەکەدا بۆت دانراوە (<?php echo $maxEmployees; ?>).
                <br>بۆیە کارمەندەکانت ناتوانن داخڵ ببن و سیستمەکە بەکاربهێنن.
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="card stats-card bg-primary text-white">
                    <div class="card-body text-center">
                        <i class="bi bi-people display-4 mb-2"></i>
                        <h3 class="mb-1"><?php echo (int)$stats['total_sub_users']; ?></h3>
                        <p class="mb-0">کۆی بەکارهێنەرانی لاوەکی</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card stats-card bg-success text-white">
                    <div class="card-body text-center">
                        <i class="bi bi-person-check display-4 mb-2"></i>
                        <h3 class="mb-1"><?php echo (int)$stats['active_sub_users']; ?></h3>
                        <p class="mb-0">بەکارهێنەرانی چالاک</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Add Sub-User Button -->
        <div class="row mb-4">
            <div class="col-12">
                <?php if ($maxEmployees <= 0): ?>
                    <button class="btn btn-secondary" disabled>
                        <i class="bi bi-person-plus"></i> زیادکردنی کارمەند
                    </button>
                    <small class="text-danger ms-2">
                        <i class="bi bi-lock-fill"></i> ئەم خزمەتگوزارییە بۆ پاکێجەکەت بەردەست نییە
                    </small>
                <?php elseif ($stats['total_sub_users'] < $maxEmployees): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubUserModal">
                        <i class="bi bi-person-plus"></i> زیادکردنی بەکارهێنەری لاوەکی
                    </button>
                    <small class="text-muted ms-2">
                        (<?php echo ($maxEmployees - $stats['total_sub_users']); ?> بەکارهێنەری تر دەتوانیت زیاد بکەیت)
                    </small>
                <?php else: ?>
                    <button class="btn btn-secondary" disabled>
                        <i class="bi bi-person-plus"></i> زیادکردنی بەکارهێنەری لاوەکی
                    </button>
                    <small class="text-danger ms-2">
                        <i class="bi bi-exclamation-circle"></i> گەیشتوویت بە سنووری (<?php echo $maxEmployees; ?> کارمەند)
                    </small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sub-Users List -->
        <div class="row">
            <?php if (empty($subUsers)): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-people display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">هیچ بەکارهێنەری لاوەکی نییە</h4>
                            <p class="text-muted">دەستت بکە بە زیادکردنی بەکارهێنەری لاوەکی</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($subUsers as $subUser): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card sub-user-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo htmlspecialchars($subUser['full_name']); ?></h5>
                                        <p class="text-muted small mb-0">@<?php echo htmlspecialchars($subUser['username']); ?></p>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="editSubUser(<?php echo htmlspecialchars(json_encode($subUser)); ?>)">
                                                <i class="bi bi-pencil"></i> دەستکاری
                                            </a></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteSubUser(<?php echo $subUser['id']; ?>, '<?php echo htmlspecialchars($subUser['username']); ?>')">
                                                <i class="bi bi-trash"></i> سڕینەوە
                                            </a></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">
                                        <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($subUser['email']); ?>
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <span class="badge bg-<?php echo $subUser['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $subUser['is_active'] ? 'چالاک' : 'ناچالاک'; ?>
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="small text-muted mb-2">دەسەڵاتەکان:</h6>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php 
                                        $permissions = $subUser['permissions'] ?? [];
                                        $permissionLabels = [
                                            'pos' => 'فرۆشتن',
                                            'products' => 'کاڵاکان',
                                            'inventory' => 'بارەکان و کۆگا',
                                            'categories' => 'کەتەلۆگەکان',
                                            'returns' => 'گەڕاندنەوەکان',
                                            'customers' => 'کڕیاران',
                                            'debts' => 'قەرزەکانی کڕیاران',
                                            'customer_info' => 'زانیاری کڕیارەکان',
                                            'reports' => 'ڕاپۆرت و ئامار',
                                            'expenses' => 'خەرجیەکان',
                                            'expense_credits' => 'قەرزی خەرجیەکان',
                                            'expense_stats' => 'ئاماری خەرجیەکان',
                                            'notebooks' => 'دەفتەری تێبینی',
                                            'dollar_price' => 'نرخی دۆلار'
                                        ];
                                        
                                        foreach ($permissions as $permission) {
                                            if (isset($permissionLabels[$permission])) {
                                                echo '<span class="badge bg-info small">' . $permissionLabels[$permission] . '</span>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php if (!empty($subUser['abac_policies'])): ?>
                                    <div class="mb-2">
                                        <h6 class="small text-muted mb-2">یاساکانی ABAC:</h6>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($subUser['abac_policies'] as $policy): ?>
                                                <span class="badge bg-<?php echo $policy['effect'] === 'deny' ? 'danger' : 'success'; ?> small">
                                                    <?php echo htmlspecialchars($policy['resource'] . '.' . $policy['action']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
               
                                
                                <?php if ($subUser['last_login']): ?>
                                    <div class="small text-muted">
                                        <i class="bi bi-clock"></i> دوایین داخڵبوون: 
                                        <?php echo date('Y/m/d H:i', strtotime($subUser['last_login'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Sub-User Modal -->
    <div class="modal fade" id="addSubUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="subUserForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="create" id="formAction">
                    <input type="hidden" name="sub_user_id" id="subUserId">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">زیادکردنی بەکارهێنەری لاوەکی</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">ناوی بەکارهێنەر *</label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">ئیمەیڵ *</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">ناوی تەواو *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">پاسۆرد <span id="passwordRequired">*</span></label>
                                    <input type="password" class="form-control" id="password" name="password">
                                    <div class="form-text">بۆ نوێکردنەوە، پاسۆردی نوێ داخڵ بکە</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ماتریکسی یاساکانی ABAC (module.action)</label>
                            <input type="hidden" name="abac_policies_json" id="abacPoliciesJson" value="[]">
                            <div id="legacyPermissionsContainer"></div>
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-outline-success btn-sm" id="abacSelectAllBtn">هەموو چالاک بکە</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="abacUnselectAllBtn">هەموو ناچالاک بکە</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle employees-manage-table">
                                    <thead>
                                        <tr>
                                            <th>مۆدیول</th>
                                            <th>بینین</th>
                                            <th>دروستکردن</th>
                                            <th>نوێکردنەوە</th>
                                            <th>سڕینەوە</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($permissionCatalog as $module => $actions): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($permissionModuleLabels[$module] ?? $module); ?></strong></td>
                                                <?php foreach (['view', 'create', 'update', 'delete'] as $matrixAction): ?>
                                                    <td>
                                                        <?php if (in_array($matrixAction, $actions, true)): ?>
                                                            <input type="checkbox" class="form-check-input abac-matrix-checkbox" data-module="<?php echo htmlspecialchars($module); ?>" data-action="<?php echo $matrixAction; ?>" checked>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">دەسەڵاتەکانی فرۆشتن (POS)</label>
                            <div class="border rounded p-2">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="permPosPriceEdit" checked>
                                    <label class="form-check-label" for="permPosPriceEdit">
                                        ڕێگەدان بە گۆڕینی <strong>نرخ</strong>ی کاڵا لە سەبەتەی فرۆشتن
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="permPosTotalEdit" checked>
                                    <label class="form-check-label" for="permPosTotalEdit">
                                        ڕێگەدان بە گۆڕینی <strong>کۆی نرخ</strong> (کۆی گشتی) لە سەبەتەی فرۆشتن
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    ئەگەر لابدرێن، ئەم کارمەندە ناتوانێت نرخ/کۆی گشتی لە پەڕەی فرۆشتن بگۆڕێت.
                                </small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">دەسەڵاتی مێژووی فرۆشتن</label>
                            <div class="border rounded p-2">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="permSalesViewAll">
                                    <label class="form-check-label" for="permSalesViewAll">
                                        ڕێگەدان بە بینینی <strong>هەموو فرۆشتنەکان</strong> (نەک تەنها فرۆشتنی خۆی)
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    ئەگەر لابدرێت، ئەم کارمەندە تەنها ئەو فرۆشتنانە دەبینێت کە خۆی تۆماری کردوون
                                    (لە مێژوو، وەسڵ، گەڕاندنەوە و ئامار).
                                </small>
                            </div>
                        </div>

                        <div class="mb-3" id="activeCheckboxDiv" style="display: none;">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                                <label class="form-check-label" for="is_active">چالاک</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">زیادکردن</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">پشتڕاستکردنەوەی سڕینەوە</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>ئایا دڵنیایت کە دەتەوێت بەکارهێنەری لاوەکی <strong id="deleteUsername"></strong> بسڕیتەوە؟</p>
                    <p class="text-danger small">ئەم کردارە ناگەڕێتەوە!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">هەڵوەشاندنەوە</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sub_user_id" id="deleteUserId">
                        <button type="submit" class="btn btn-danger">سڕینەوە</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let hiddenCustomPolicies = [];

        // Toggle-کان بۆ ڕاگرتنی گۆڕینی نرخ/کۆی گشتی لە POS (مۆدێلی deny).
        // چێککراو = ڕێگەپێدراو (هیچ یاسا)؛ چێک لابراو = یاسای deny زیاد دەکرێت.
        const posDenyToggleConfig = [
            { id: 'permPosPriceEdit', resource: 'pos', action: 'price_edit' },
            { id: 'permPosTotalEdit', resource: 'pos', action: 'total_edit' }
        ];

        function buildPosDenyPolicies() {
            const policies = [];
            posDenyToggleConfig.forEach((cfg) => {
                const cb = document.getElementById(cfg.id);
                if (cb && !cb.checked) {
                    policies.push({
                        resource: cfg.resource,
                        action: cfg.action,
                        effect: 'deny',
                        priority: 10,
                        conditions: []
                    });
                }
            });
            return policies;
        }

        function isManagedPosDenyPolicy(policy) {
            if (!policy || policy.effect !== 'deny') {
                return false;
            }
            return posDenyToggleConfig.some((cfg) =>
                cfg.resource === policy.resource && cfg.action === policy.action);
        }

        // Toggle ی «بینینی هەموو فرۆشتنەکان» (مۆدێلی allow).
        // چێککراو = یاسای allow ـی sales.view_all زیاد دەکرێت؛ ئەگینا کارمەند تەنها فرۆشتنی خۆی دەبینێت.
        // priority=60 وا دەکات وەک یاسای سادەی matrix هەڵنەسەنگێنرێت (نەک 100).
        const salesAllowToggleConfig = [
            { id: 'permSalesViewAll', resource: 'sales', action: 'view_all' }
        ];

        function buildSalesAllowPolicies() {
            const policies = [];
            salesAllowToggleConfig.forEach((cfg) => {
                const cb = document.getElementById(cfg.id);
                if (cb && cb.checked) {
                    policies.push({
                        resource: cfg.resource,
                        action: cfg.action,
                        effect: 'allow',
                        priority: 60,
                        conditions: []
                    });
                }
            });
            return policies;
        }

        function isManagedSalesAllowPolicy(policy) {
            if (!policy || policy.effect !== 'allow') {
                return false;
            }
            return salesAllowToggleConfig.some((cfg) =>
                cfg.resource === policy.resource && cfg.action === policy.action);
        }

        function buildMatrixPolicies() {
            const policies = [];
            document.querySelectorAll('.abac-matrix-checkbox:checked').forEach((checkbox) => {
                policies.push({
                    resource: checkbox.dataset.module,
                    action: checkbox.dataset.action,
                    effect: 'allow',
                    priority: 100,
                    conditions: []
                });
            });
            return policies;
        }

        function isSimpleMatrixAllowPolicy(policy) {
            if (!policy || typeof policy !== 'object') {
                return false;
            }
            const conditions = Array.isArray(policy.conditions) ? policy.conditions : [];
            const priority = Number(policy.priority ?? 100);
            return policy.effect === 'allow' && conditions.length === 0 && priority === 100;
        }

        function syncAbacJsonField() {
            const policies = buildMatrixPolicies()
                .concat(buildPosDenyPolicies())
                .concat(buildSalesAllowPolicies())
                .concat(hiddenCustomPolicies);
            document.getElementById('abacPoliciesJson').value = JSON.stringify(policies);
            return true;
        }

        function syncLegacyPermissionsField() {
            const container = document.getElementById('legacyPermissionsContainer');
            container.innerHTML = '';

            const legacyModules = new Set();
            document.querySelectorAll('.abac-matrix-checkbox[data-action="view"]:checked').forEach((checkbox) => {
                legacyModules.add(checkbox.dataset.module);
            });

            legacyModules.forEach((module) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'permissions[]';
                input.value = module;
                container.appendChild(input);
            });
        }

        function setAllMatrixCheckboxes(checked) {
            document.querySelectorAll('.abac-matrix-checkbox').forEach((checkbox) => {
                checkbox.checked = checked;
            });
            syncLegacyPermissionsField();
        }

        function editSubUser(subUser) {
            document.getElementById('formAction').value = 'update';
            document.getElementById('subUserId').value = subUser.id;
            document.getElementById('modalTitle').textContent = 'دەستکاریکردنی بەکارهێنەری لاوەکی';
            document.getElementById('submitBtn').textContent = 'نوێکردنەوە';
            document.getElementById('passwordRequired').style.display = 'none';
            document.getElementById('activeCheckboxDiv').style.display = 'block';
            
            // Fill form
            document.getElementById('username').value = subUser.username;
            document.getElementById('email').value = subUser.email;
            document.getElementById('full_name').value = subUser.full_name;
            document.getElementById('is_active').checked = subUser.is_active == 1;
            
            // Clear password
            document.getElementById('password').value = '';
            
            const permissions = subUser.permissions || [];

            const existingPolicies = Array.isArray(subUser.abac_policies) ? subUser.abac_policies : [];
            const matrixPolicyKeys = new Set();
            hiddenCustomPolicies = [];

            // بنەڕەت: هەردوو toggle ی POS چێککراون (ڕێگەپێدراو)
            posDenyToggleConfig.forEach((cfg) => {
                const cb = document.getElementById(cfg.id);
                if (cb) { cb.checked = true; }
            });

            // بنەڕەت: toggle ی «بینینی هەموو فرۆشتن» چێک‌نەکراوە (تەنها فرۆشتنی خۆی)
            salesAllowToggleConfig.forEach((cfg) => {
                const cb = document.getElementById(cfg.id);
                if (cb) { cb.checked = false; }
            });

            existingPolicies.forEach((policy) => {
                if (isManagedPosDenyPolicy(policy)) {
                    // یاسای deny بۆ نرخ/کۆی گشتی → toggle ـەکە لاببە (چێک لاببە)
                    const cfg = posDenyToggleConfig.find((c) =>
                        c.resource === policy.resource && c.action === policy.action);
                    const cb = cfg ? document.getElementById(cfg.id) : null;
                    if (cb) { cb.checked = false; }
                    return;
                }
                if (isManagedSalesAllowPolicy(policy)) {
                    // یاسای allow ی sales.view_all → toggle ـەکە چێک بکە
                    const cfg = salesAllowToggleConfig.find((c) =>
                        c.resource === policy.resource && c.action === policy.action);
                    const cb = cfg ? document.getElementById(cfg.id) : null;
                    if (cb) { cb.checked = true; }
                    return;
                }
                if (isSimpleMatrixAllowPolicy(policy)) {
                    const key = `${policy.resource}.${policy.action}`;
                    matrixPolicyKeys.add(key);
                } else {
                    hiddenCustomPolicies.push(policy);
                }
            });

            document.querySelectorAll('.abac-matrix-checkbox').forEach((checkbox) => {
                const key = `${checkbox.dataset.module}.${checkbox.dataset.action}`;
                if (matrixPolicyKeys.size > 0) {
                    checkbox.checked = matrixPolicyKeys.has(key);
                } else {
                    // fallback for old rows that only had legacy permissions
                    checkbox.checked = permissions.includes(checkbox.dataset.module);
                }
            });
            syncLegacyPermissionsField();
            
            // Show modal
            new bootstrap.Modal(document.getElementById('addSubUserModal')).show();
        }
        
        function deleteSubUser(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Reset form when modal is hidden
        document.getElementById('addSubUserModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('subUserForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('subUserId').value = '';
            document.getElementById('modalTitle').textContent = 'زیادکردنی بەکارهێنەری لاوەکی';
            document.getElementById('submitBtn').textContent = 'زیادکردن';
            document.getElementById('passwordRequired').style.display = 'inline';
            document.getElementById('activeCheckboxDiv').style.display = 'none';
            document.getElementById('abacPoliciesJson').value = '[]';
            hiddenCustomPolicies = [];
            document.querySelectorAll('.abac-matrix-checkbox').forEach((checkbox) => {
                checkbox.checked = true;
            });
            // بنەڕەتی کارمەندی نوێ: تەنها فرۆشتنی خۆی (toggle چێک‌نەکراو)
            salesAllowToggleConfig.forEach((cfg) => {
                const cb = document.getElementById(cfg.id);
                if (cb) { cb.checked = false; }
            });
            syncLegacyPermissionsField();
        });

        document.getElementById('subUserForm').addEventListener('submit', function (event) {
            syncLegacyPermissionsField();
            if (!syncAbacJsonField()) {
                event.preventDefault();
            }
        });

        const abacSelectAllBtn = document.getElementById('abacSelectAllBtn');
        if (abacSelectAllBtn) {
            abacSelectAllBtn.addEventListener('click', () => {
                setAllMatrixCheckboxes(true);
            });
        }

        const abacUnselectAllBtn = document.getElementById('abacUnselectAllBtn');
        if (abacUnselectAllBtn) {
            abacUnselectAllBtn.addEventListener('click', () => {
                setAllMatrixCheckboxes(false);
            });
        }

        syncLegacyPermissionsField();
    </script>
</body>
</html>
