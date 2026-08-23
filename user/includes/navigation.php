<?php
/**
 * ناڤیگەیشنی بەکارهێنەر بەپێی دەسەڵاتەکان - user/includes/navigation.php
 */

require_once '../../includes/permissions.php';

/**
 * دروستکردنی مینووی ناڤیگەیشن بەپێی دەسەڵاتەکان
 */
function renderUserNavigation($currentUser, $currentSection = '') {
    $userId = (int)($currentUser['id'] ?? 0);
    $packageInfo = getUserPackageInfo($userId);
    
    // نەخشەی بەشەکان
    $sections = [
        'dashboard' => [
            'label' => 'داشبۆرد',
            'icon' => 'bi-speedometer2',
            'url' => 'user/dashboard/index.php',
            'color' => 'primary',
            'permission' => 'dashboard.view'
        ],
        'pos' => [
            'label' => 'فرۆشتن',
            'icon' => 'bi-cash-register',
            'url' => 'user/pos/index.php',
            'color' => 'success',
            'permission' => 'pos.view'
        ],
        'products' => [
            'label' => 'کاڵاکان',
            'icon' => 'bi-box',
            'url' => 'user/products/index.php',
            'color' => 'info',
            'permission' => 'products.view'
        ],
        'sales' => [
            'label' => 'فرۆشراوەکان',
            'icon' => 'bi-graph-up',
            'url' => 'user/sales/view.php',
            'color' => 'warning',
            'permission' => 'pos.view'
        ],
        'employees' => [
            'label' => 'کارمەندان',
            'icon' => 'bi-people',
            'url' => 'user/employees/index.php',
            'color' => 'primary',
            'permission' => 'employees.view'
        ],
        'customers' => [
            'label' => 'کڕیارەکان',
            'icon' => 'bi-people',
            'url' => 'user/customers/index.php',
            'color' => 'primary',
            'permission' => 'customers.view'
        ],
        'reports' => [
            'label' => 'ڕاپۆرتەکان',
            'icon' => 'bi-file-earmark-text',
            'url' => 'user/reports/index.php',
            'color' => 'secondary',
            'permission' => 'reports.view'
        ],
        'settings' => [
            'label' => 'ڕێکخستنەکان',
            'icon' => 'bi-gear',
            'url' => 'user/settings/index.php',
            'color' => 'dark',
            'permission' => 'settings.view'
        ],
        'expenses' => [
            'label' => 'خەرجییەکان',
            'icon' => 'bi-wallet2',
            'url' => 'user/expenses/index.php',
            'color' => 'danger',
            'permission' => 'expenses.view'
        ],
        'purchases' => [
            'label' => 'کڕینەکان',
            'icon' => 'bi-cart-plus',
            'url' => 'user/purchases/index.php',
            'color' => 'success',
            'permission' => 'purchases.view'
        ],
        'returns' => [
            'label' => 'گەڕاندنەوەکان',
            'icon' => 'bi-arrow-return-left',
            'url' => 'user/returns/index.php',
            'color' => 'warning',
            'permission' => 'returns.view'
        ],
        'companies' => [
            'label' => 'کۆمپانیاکان',
            'icon' => 'bi-building',
            'url' => 'user/companies/index.php',
            'color' => 'info',
            'permission' => 'companies.view'
        ],
        'notebooks' => [
            'label' => 'تێبینییەکان',
            'icon' => 'bi-journal-text',
            'url' => 'user/notebooks/index.php',
            'color' => 'primary',
            'permission' => 'notebooks.view'
        ],
        'wallets' => [
            'label' => 'قاسەکان',
            'icon' => 'bi-wallet2',
            'url' => 'user/wallets/main.php',
            'color' => 'success',
            'permission' => 'wallets.view'
        ]
    ];
    
    ?>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo url('user/dashboard/index.php'); ?>">
                <i class="bi bi-shop"></i>
                <?php echo htmlspecialchars($currentUser['business_name']); ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach ($sections as $sectionKey => $section): ?>
                        <?php
                        $canRenderSection = false;
                        if ($sectionKey === 'wallets') {
                            $canRenderSection = canCurrentUserAccessWalletsModule([
                                'route' => $section['url'],
                                'request_method' => 'GET'
                            ]);
                        } else {
                            $canView = authorize($currentUser, $section['permission'] ?? ($sectionKey . '.view'), [
                                'route' => $section['url'],
                                'request_method' => 'GET'
                            ]);
                            $canRenderSection = !empty($canView['allowed']);
                        }
                        ?>
                        <?php if ($canRenderSection): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentSection === $sectionKey ? 'active' : ''; ?>" 
                                   href="<?php echo url($section['url']); ?>">
                                    <i class="bi <?php echo $section['icon']; ?>"></i>
                                    <?php echo $section['label']; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <!-- Package Info -->
                    <?php if ($packageInfo): ?>
                        <li class="nav-item">
                            <span class="navbar-text">
                                <i class="bi bi-box-seam"></i>
                                <small><?php echo htmlspecialchars($packageInfo['name']); ?></small>
                            </span>
                        </li>
                    <?php endif; ?>
                    
                    <!-- User Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" 
                           data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i>
                            <?php echo htmlspecialchars($currentUser['business_name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if (!empty(authorize($currentUser, 'settings.view', ['route' => 'user/settings/index.php', 'request_method' => 'GET'])['allowed'])): ?>
                                <li><a class="dropdown-item" href="<?php echo url('user/settings/index.php'); ?>">
                                    <i class="bi bi-gear"></i> ڕێکخستنەکان
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="<?php echo url('user/auth/logout.php'); ?>">
                                <i class="bi bi-box-arrow-right"></i> دەرچوون
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php
}

/**
 * دروستکردنی کارتەکانی داشبۆرد بەپێی دەسەڵاتەکان
 */
function renderDashboardCards($currentUser) {
    // نەخشەی کارتەکان
    $cards = [
        'pos' => [
            'title' => 'بەشی فرۆشتن',
            'icon' => 'bi-cash-coin',
            'color' => 'section-sales',
            'url' => 'user/pos/index.php',
            'stats' => ['today_sales', 'today_revenue'],
            'actions' => [
                ['label' => 'فرۆشتنی نوێ', 'url' => 'user/pos/index.php', 'icon' => 'bi-plus-lg'],
                ['label' => 'لیستی فرۆشتنەکان', 'url' => 'user/pos/sales.php', 'icon' => 'bi-list']
            ]
        ],
        'products' => [
            'title' => 'بەڕێوەبردنی کاڵاکان',
            'icon' => 'bi-box',
            'color' => 'section-products',
            'url' => 'user/products/index.php',
            'stats' => ['total_products', 'low_stock_products'],
            'actions' => [
                ['label' => 'کاڵای نوێ', 'url' => 'user/products/add.php', 'icon' => 'bi-plus-lg'],
                ['label' => 'لیستی کاڵاکان', 'url' => 'user/products/index.php', 'icon' => 'bi-list']
            ]
        ],
        'customers' => [
            'title' => 'بەڕێوەبردنی کڕیارەکان',
            'icon' => 'bi-people',
            'color' => 'section-customers',
            'url' => 'user/customers/index.php',
            'stats' => ['total_customers', 'active_debts'],
            'actions' => [
                ['label' => 'کڕیاری نوێ', 'url' => 'user/customers/add.php', 'icon' => 'bi-plus-lg'],
                ['label' => 'لیستی کڕیارەکان', 'url' => 'user/customers/index.php', 'icon' => 'bi-list']
            ]
        ],
        'expenses' => [
            'title' => 'بەڕێوەبردنی خەرجییەکان',
            'icon' => 'bi-wallet2',
            'color' => 'section-expenses',
            'url' => 'user/expenses/index.php',
            'stats' => ['today_expenses', 'monthly_expenses'],
            'actions' => [
                ['label' => 'خەرجی نوێ', 'url' => 'user/expenses/add.php', 'icon' => 'bi-plus-lg'],
                ['label' => 'لیستی خەرجییەکان', 'url' => 'user/expenses/index.php', 'icon' => 'bi-list']
            ]
        ],
        'reports' => [
            'title' => 'ڕاپۆرت و ئامارەکان',
            'icon' => 'bi-graph-up',
            'color' => 'section-reports',
            'url' => 'user/reports/index.php',
            'stats' => ['monthly_sales', 'monthly_revenue'],
            'actions' => [
                ['label' => 'بەشی قازانج', 'url' => 'user/reports/index.php', 'icon' => 'bi-wallet2']
            ]
        ],
        'returns' => [
            'title' => 'بەڕێوەبردنی گەڕاندنەوەکان',
            'icon' => 'bi-arrow-return-left',
            'color' => 'section-returns',
            'url' => 'user/returns/index.php',
            'stats' => ['total_returns', 'today_returns'],
            'actions' => [
                ['label' => 'گەڕاندنەوەی نوێ', 'url' => 'user/returns/add.php', 'icon' => 'bi-plus-lg'],
                ['label' => 'لیستی گەڕاندنەوەکان', 'url' => 'user/returns/index.php', 'icon' => 'bi-list']
            ]
        ],
        'wallets' => [
            'title' => 'بەڕێوەبردنی قاسەکان',
            'icon' => 'bi-wallet2',
            'color' => 'section-expenses',
            'url' => 'user/wallets/main.php',
            'stats' => ['today_expenses', 'monthly_expenses'],
            'actions' => [
                ['label' => 'لیستی قاسەکان', 'url' => 'user/wallets/index.php', 'icon' => 'bi-list'],
                ['label' => 'گواستنەوە', 'url' => 'user/wallets/transfer.php', 'icon' => 'bi-arrow-left-right']
            ]
        ]
    ];
    
    ?>
    <div class="row g-4 mb-4">
        <?php foreach ($cards as $cardKey => $card): ?>
            <?php
            $canRenderCard = false;
            if ($cardKey === 'wallets') {
                $canRenderCard = canCurrentUserAccessWalletsModule([
                    'route' => $card['url'],
                    'request_method' => 'GET'
                ]);
            } else {
                $canRenderCard = !empty(authorize($currentUser, $cardKey . '.view', [
                    'route' => $card['url'],
                    'request_method' => 'GET'
                ])['allowed']);
            }
            ?>
            <?php if ($canRenderCard): ?>
                <div class="col-lg-3 col-md-6">
                    <div class="dashboard-card <?php echo $card['color']; ?> p-4">
                        <div class="text-center text-white">
                            <div class="card-icon bg-white bg-opacity-20 mx-auto">
                                <i class="bi <?php echo $card['icon']; ?> text-primary"></i>
                            </div>
                            <h4 class="mb-3"><?php echo $card['title']; ?></h4>
                            <div class="mt-3">
                                <?php foreach ($card['actions'] as $action): ?>
                                    <a href="<?php echo url($action['url']); ?>" class="action-btn btn btn-light me-2">
                                        <i class="bi <?php echo $action['icon']; ?>"></i> <?php echo $action['label']; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * چیکردنی دەسەڵات و ڕێگەگرتن لە دەستپێگەیشتن
 */
function checkPagePermission($requiredSection, $redirectUrl = 'user/dashboard/index.php') {
    $currentUser = getCurrentUser();
    $result = authorize($currentUser, $requiredSection . '.view', [
        'route' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ]);
    if (!$result['allowed']) {
        setMessage('دەسەڵاتت نییە بۆ دەستپێگەیشتنی ئەم بەشە', 'danger');
        redirect($redirectUrl);
    }
}

?>
