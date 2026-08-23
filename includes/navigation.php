<?php
/**
 * Navigation سەرەکی - includes/navigation.php
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['REQUEST_URI'];

// Determine current section
$isAdmin = isAdmin();
$isUser = isUser();
$currentUser = getCurrentUser();
$currentAdmin = getCurrentAdmin();

?>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary kasher-navbar sticky-top no-print">
    <div class="container-fluid">
        <!-- Brand -->
        <a class="navbar-brand" href="<?php echo url(); ?>">
            <i class="bi bi-shop"></i>
            <span class="d-none d-md-inline"><?php echo SITE_NAME; ?></span>
            <span class="d-md-none">POS</span>
        </a>
        
        <!-- Mobile toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            
            <!-- Admin Navigation -->
            <?php if ($isAdmin): ?>
                <ul class="navbar-nav me-auto"></ul>
                
            <!-- User Navigation -->
            <?php elseif ($isUser): ?>
                <ul class="navbar-nav me-auto"></ul>
                
            <!-- Guest Navigation -->
            <?php else: ?>
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url(); ?>">
                            <i class="bi bi-house"></i> سەرەتا
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('user/auth/register.php'); ?>">
                            <i class="bi bi-person-plus"></i> تۆمارکردن
                        </a>
                    </li>
                </ul>
            <?php endif; ?>
            
            <!-- Right Side Navigation -->
            <ul class="navbar-nav">
                <?php if ($isUser || $isAdmin): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($isUser && strpos($currentPath, '/user/dashboard') !== false) || ($isAdmin && strpos($currentPath, '/adminKx9mZpQa7WvRt4Ny6Lb3/index.php') !== false) ? 'active' : ''; ?>" 
                           href="<?php echo url($isUser ? 'user/dashboard/index.php' : 'adminKx9mZpQa7WvRt4Ny6Lb3/index.php'); ?>">
                            <i class="bi bi-speedometer2"></i>
                            <span class="d-lg-none d-xl-inline">داشبۆرد</span>
                        </a>
                    </li>
                    <?php
                    // Switcher ی چەندین بزنس — تەنها بۆ خاوەنی ڕێکخراو کە ≥٢ بزنسی هەیە
                    $switchCtx = ($isUser && function_exists('getBusinessSwitchContext')) ? getBusinessSwitchContext() : null;
                    if ($isUser && $switchCtx !== null && count($switchCtx['businesses']) >= 2):
                        $activeBizId = (int)$switchCtx['active_id'];
                        $activeName = '';
                        foreach ($switchCtx['businesses'] as $b) {
                            if ($b['id'] === $activeBizId) { $activeName = $b['business_name']; break; }
                        }
                        $switchCsrf = Security::generateCSRFToken();
                    ?>
                        <li class="nav-item dropdown business-switcher">
                            <a class="nav-link dropdown-toggle" href="#" id="businessSwitcherDropdown" role="button"
                               data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-shop-window"></i>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($activeName ?? ''); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="businessSwitcherDropdown">
                                <li><h6 class="dropdown-header"><i class="bi bi-diagram-3"></i> بزنسەکان</h6></li>
                                <?php foreach ($switchCtx['businesses'] as $b): ?>
                                    <?php
                                        $isActiveBiz = ($b['id'] === $activeBizId);
                                        $isUsable = ($b['status'] === 'approved') && (empty($b['expiration_date']) || strtotime($b['expiration_date']) >= time());
                                    ?>
                                    <li>
                                        <form method="POST" action="<?php echo url('user/switch_business.php'); ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $switchCsrf; ?>">
                                            <input type="hidden" name="business_id" value="<?php echo (int)$b['id']; ?>">
                                            <button type="submit" class="dropdown-item d-flex align-items-center justify-content-between<?php echo $isActiveBiz ? ' active' : ''; ?>"<?php echo (!$isUsable && !$isActiveBiz) ? ' disabled' : ''; ?>>
                                                <span>
                                                    <i class="bi <?php echo $isActiveBiz ? 'bi-check-circle-fill' : 'bi-shop'; ?>"></i>
                                                    <?php echo htmlspecialchars($b['business_name'] ?? ''); ?>
                                                </span>
                                                <?php if (!$isUsable): ?><small class="text-danger">ناچالاک</small><?php endif; ?>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo url('user/reports/businesses_overview.php'); ?>">
                                        <i class="bi bi-bar-chart-line"></i> ڕاپۆرتی گشتی هەموو بزنسەکان
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <span class="nav-link">
                                <i class="bi bi-person-circle"></i>
                                <span class="d-none d-md-inline">
                                    <?php
                                    if ($isUser) {
                                        echo htmlspecialchars($currentUser['business_name'] ?? $currentUser['username'] ?? $currentUser['full_name'] ?? '');
                                    } else {
                                        echo htmlspecialchars($currentAdmin['username'] ?? '');
                                    }
                                    ?>
                                </span>
                            </span>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link logout-link" 
                           href="<?php echo url($isUser ? 'user/auth/logout.php' : 'adminKx9mZpQa7WvRt4Ny6Lb3/logout.php'); ?>">
                            <i class="bi bi-box-arrow-right"></i> دەرچوون
                        </a>
                    </li>
                    
                <?php else: ?>
                    <!-- Login Links for Guests -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('adminKx9mZpQa7WvRt4Ny6Lb3/login.php'); ?>">
                            <i class="bi bi-person-gear"></i>
                            <span class="d-none d-md-inline">بەڕێوەبەر</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('user/auth/login.php'); ?>">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span class="d-none d-md-inline">داخڵبوون</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>