<?php
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Get filter parameters
$filter = $_GET['filter'] ?? 'expired';
$customDays = isset($_GET['custom_days']) ? max(1, (int)$_GET['custom_days']) : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query based on filter
$whereClause = "p.user_id = ? AND p.expiry_date IS NOT NULL";
$params = [$userId];
$types = 'i';
$title = '';

switch ($filter) {
    case 'expired':
        $whereClause .= " AND p.expiry_date <= CURDATE()";
        $title = 'کاڵا بەسەرچووەکان';
        break;
        
    case 'expiring_soon':
        $whereClause .= " AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        $title = 'کاڵا نزیک لە بەسەرچوون (30 ڕۆژی داهاتوو)';
        break;
        
    case 'expiring_2months':
        $whereClause .= " AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
        $title = 'کاڵا نزیک لە بەسەرچوون (نزیک ٢ مانگ)';
        break;
        
    case 'expiring_3months':
        $whereClause .= " AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
        $title = 'کاڵا نزیک لە بەسەرچوون (نزیک ٣ مانگ)';
        break;
        
    case 'expiring_week':
        $whereClause .= " AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $title = 'کاڵا نزیک لە بەسەرچوون (حەفتەیەکی داهاتوو)';
        break;
        
    case 'custom_days':
        if ($customDays !== null && $customDays > 0) {
            $whereClause .= " AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $params[] = $customDays;
            $types .= 'i';
            $title = "کاڵا نزیک لە بەسەرچوون ({$customDays} ڕۆژی داهاتوو)";
        } else {
            $whereClause .= " AND p.expiry_date <= CURDATE()";
            $title = 'کاڵا بەسەرچووەکان';
        }
        break;
        
    default:
        $whereClause .= " AND p.expiry_date <= CURDATE()";
        $title = 'کاڵا بەسەرچووەکان';
}

// بڕی بەردەست (stock) — تەنها کاڵایەک پیشان بدرێت کە بڕی بەردەستی زیاترە لە سفر.
// کاتێک بڕی بەردەست دەبێتە سفر، لە لیستی بەسەرچووەکان (و ماوەکانی دیکە) پیشان نادرێت.
$stockExpr = "COALESCE(
    (SELECT pu_primary.stock_quantity FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.stock_quantity FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";
$whereClause .= " AND $stockExpr > 0";

// Get expired products
$query = "
    SELECT p.*, c.name as category_name,
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
               (SELECT pu_primary.sell_price FROM product_units pu_primary
                WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                ORDER BY pu_primary.id ASC LIMIT 1),
               (SELECT pu_any.sell_price FROM product_units pu_any
                WHERE pu_any.product_id = p.id
                ORDER BY pu_any.id ASC LIMIT 1),
               0
           ) as sell_price,
           COALESCE(
               (SELECT pu_primary.buy_price FROM product_units pu_primary
                WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                ORDER BY pu_primary.id ASC LIMIT 1),
               (SELECT pu_any.buy_price FROM product_units pu_any
                WHERE pu_any.product_id = p.id
                ORDER BY pu_any.id ASC LIMIT 1),
               0
           ) as buy_price,
           DATEDIFF(CURDATE(), p.expiry_date) as days_expired,
           CASE 
               WHEN p.expiry_date <= CURDATE() THEN 'expired'
               WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'expiring_week'
               WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_month'
               WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'expiring_2months'
               WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'expiring_3months'
               ELSE 'safe'
           END as expiry_status
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $whereClause
    ORDER BY p.expiry_date ASC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM products p WHERE $whereClause";
$countStmt = $conn->prepare($countQuery);
$countParams = array_slice($params, 0, -2);
$countTypes = substr($types, 0, -2);
if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}
$countStmt->execute();
$totalProducts = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalProducts / $limit);

// Get statistics
$stats = [];

// Expired products
$stmt = $conn->prepare("
    SELECT COUNT(*) as count,
           COALESCE(SUM(
               COALESCE(
                   (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                    WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    ORDER BY pu_primary.id ASC LIMIT 1),
                   (SELECT pu_any.stock_quantity FROM product_units pu_any
                    WHERE pu_any.product_id = p.id
                    ORDER BY pu_any.id ASC LIMIT 1),
                   0
               )
           ), 0) as stock
    FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE()
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
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stats['expired'] = $result;

// Expiring this week
$stmt = $conn->prepare("
    SELECT COUNT(*) as count,
           COALESCE(SUM(
               COALESCE(
                   (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                    WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    ORDER BY pu_primary.id ASC LIMIT 1),
                   (SELECT pu_any.stock_quantity FROM product_units pu_any
                    WHERE pu_any.product_id = p.id
                    ORDER BY pu_any.id ASC LIMIT 1),
                   0
               )
           ), 0) as stock
    FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stats['expiring_week'] = $result;

// Expiring this month
$stmt = $conn->prepare("
    SELECT COUNT(*) as count,
           COALESCE(SUM(
               COALESCE(
                   (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                    WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    ORDER BY pu_primary.id ASC LIMIT 1),
                   (SELECT pu_any.stock_quantity FROM product_units pu_any
                    WHERE pu_any.product_id = p.id
                    ORDER BY pu_any.id ASC LIMIT 1),
                   0
               )
           ), 0) as stock
    FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stats['expiring_month'] = $result;

// Expiring in 2 months
$stmt = $conn->prepare("
    SELECT COUNT(*) as count,
           COALESCE(SUM(
               COALESCE(
                   (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                    WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    ORDER BY pu_primary.id ASC LIMIT 1),
                   (SELECT pu_any.stock_quantity FROM product_units pu_any
                    WHERE pu_any.product_id = p.id
                    ORDER BY pu_any.id ASC LIMIT 1),
                   0
               )
           ), 0) as stock
    FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stats['expiring_2months'] = $result;

// Expiring in 3 months
$stmt = $conn->prepare("
    SELECT COUNT(*) as count,
           COALESCE(SUM(
               COALESCE(
                   (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                    WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    ORDER BY pu_primary.id ASC LIMIT 1),
                   (SELECT pu_any.stock_quantity FROM product_units pu_any
                    WHERE pu_any.product_id = p.id
                    ORDER BY pu_any.id ASC LIMIT 1),
                   0
               )
           ), 0) as stock
    FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date > CURDATE() AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stats['expiring_3months'] = $result;

// هەموو IDی کاڵا بەسەرچووەکان کە بڕی بەردەستیان هەیە — بۆ کرداری گشتی «نوێکردنەوەی بەردەست»
$expiredIdsForBulk = [];
$bulkStmt = $conn->prepare("
    SELECT p.id FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE()
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
$bulkStmt->bind_param('i', $userId);
$bulkStmt->execute();
$bulkResult = $bulkStmt->get_result();
while ($bulkRow = $bulkResult->fetch_assoc()) {
    $expiredIdsForBulk[] = (int)$bulkRow['id'];
}

$pageTitle = $title;
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo $pageTitle; ?> - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-subpages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="products-module-page products-expired-page">

    <?php
    $productsNavId = 'productsExpiredNav';
    $productsNavLinks = [
        ['href' => url('user/products/index.php'), 'icon' => 'bi-box-seam', 'text' => 'لیستی کاڵاکان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container-fluid py-4 products-page-content pp-wrap">
        
        <?php
        $message = getMessage();
        if ($message):
        ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle-fill"></i>
                <?php echo $message['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <header class="pp-hero">
            <div>
                <div class="pp-kicker"><i class="bi bi-calendar-x"></i> بەرواری بەسەرچوون</div>
                <h1><i class="bi bi-hourglass-split"></i> <?php echo $title; ?></h1>
                <p class="pp-hero-sub">بەدواداچوون و کۆنتڕۆڵی کاڵا بەسەرچووەکان و نزیک لە بەسەرچوون</p>
                <div class="pp-hero-pills">
                    <span class="pp-pill"><i class="bi bi-list-ul"></i> <?php echo number_format($totalProducts); ?> کاڵا</span>
                    <span class="pp-pill"><i class="bi bi-funnel"></i> <?php echo htmlspecialchars($filter); ?></span>
                </div>
            </div>
            <div class="pp-actions">
                <a href="<?php echo url('user/products/add.php'); ?>" class="pp-btn pp-btn-success">
                    <i class="bi bi-plus-lg"></i> کاڵای نوێ
                </a>
                <a href="<?php echo url('user/products/index.php'); ?>" class="pp-btn pp-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </header>

        <div class="pp-stats pp-stats-5">
            <a class="pp-stat<?php echo $filter === 'expired' ? ' is-active' : ''; ?>" style="--stat-accent:#ef4444" href="?filter=expired">
                <div class="pp-stat-icon"><i class="bi bi-calendar-x"></i></div>
                <div>
                    <div class="pp-stat-label">بەسەرچووەکان</div>
                    <div class="pp-stat-value"><?php echo number_format($stats['expired']['count']); ?></div>
                    <div class="pp-stat-meta"><?php echo number_format($stats['expired']['stock']); ?> دانە</div>
                </div>
            </a>
            <a class="pp-stat<?php echo $filter === 'expiring_week' ? ' is-active' : ''; ?>" style="--stat-accent:#f59e0b" href="?filter=expiring_week">
                <div class="pp-stat-icon"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="pp-stat-label">نزیک (حەفتە)</div>
                    <div class="pp-stat-value"><?php echo number_format($stats['expiring_week']['count']); ?></div>
                    <div class="pp-stat-meta"><?php echo number_format($stats['expiring_week']['stock']); ?> دانە</div>
                </div>
            </a>
            <a class="pp-stat<?php echo $filter === 'expiring_soon' ? ' is-active' : ''; ?>" style="--stat-accent:#3b82f6" href="?filter=expiring_soon">
                <div class="pp-stat-icon"><i class="bi bi-calendar-week"></i></div>
                <div>
                    <div class="pp-stat-label">نزیک (مانگ)</div>
                    <div class="pp-stat-value"><?php echo number_format($stats['expiring_month']['count']); ?></div>
                    <div class="pp-stat-meta"><?php echo number_format($stats['expiring_month']['stock']); ?> دانە</div>
                </div>
            </a>
            <a class="pp-stat<?php echo $filter === 'expiring_2months' ? ' is-active' : ''; ?>" style="--stat-accent:#8b5cf6" href="?filter=expiring_2months">
                <div class="pp-stat-icon"><i class="bi bi-calendar-month"></i></div>
                <div>
                    <div class="pp-stat-label">نزیک (٢ مانگ)</div>
                    <div class="pp-stat-value"><?php echo number_format($stats['expiring_2months']['count']); ?></div>
                    <div class="pp-stat-meta"><?php echo number_format($stats['expiring_2months']['stock']); ?> دانە</div>
                </div>
            </a>
            <a class="pp-stat<?php echo $filter === 'expiring_3months' ? ' is-active' : ''; ?>" style="--stat-accent:#f97316" href="?filter=expiring_3months">
                <div class="pp-stat-icon"><i class="bi bi-calendar-range"></i></div>
                <div>
                    <div class="pp-stat-label">نزیک (٣ مانگ)</div>
                    <div class="pp-stat-value"><?php echo number_format($stats['expiring_3months']['count']); ?></div>
                    <div class="pp-stat-meta"><?php echo number_format($stats['expiring_3months']['stock']); ?> دانە</div>
                </div>
            </a>
        </div>

        <div class="pp-panel mb-4">
            <div class="pp-panel-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="pp-chips">
                        <a href="?filter=expired" class="pp-chip is-danger<?php echo $filter === 'expired' ? ' is-active' : ''; ?>">
                            <i class="bi bi-calendar-x"></i> بەسەرچووەکان
                        </a>
                        <a href="?filter=expiring_week" class="pp-chip is-warn<?php echo $filter === 'expiring_week' ? ' is-active' : ''; ?>">
                            <i class="bi bi-clock"></i> نزیک (حەفتە)
                        </a>
                        <a href="?filter=expiring_soon" class="pp-chip<?php echo $filter === 'expiring_soon' ? ' is-active' : ''; ?>">
                            <i class="bi bi-calendar-week"></i> نزیک (مانگ)
                        </a>
                        <a href="?filter=expiring_2months" class="pp-chip<?php echo $filter === 'expiring_2months' ? ' is-active' : ''; ?>">
                            <i class="bi bi-calendar-month"></i> نزیک (٢ مانگ)
                        </a>
                        <a href="?filter=expiring_3months" class="pp-chip<?php echo $filter === 'expiring_3months' ? ' is-active' : ''; ?>">
                            <i class="bi bi-calendar-range"></i> نزیک (٣ مانگ)
                        </a>
                    </div>
                    <form method="GET" action="" class="d-flex align-items-center gap-2">
                        <input type="hidden" name="filter" value="custom_days">
                        <label for="custom_days" class="mb-0 text-muted small">ڕۆژ بەدڵخواز:</label>
                        <input type="number"
                               id="custom_days"
                               name="custom_days"
                               class="form-control form-control-sm"
                               style="width: 90px;"
                               min="1"
                               max="365"
                               value="<?php echo $filter === 'custom_days' && $customDays ? htmlspecialchars($customDays) : ''; ?>"
                               placeholder="70">
                        <button type="submit" class="pp-btn pp-btn-primary pp-btn-sm">
                            <i class="bi bi-search"></i> نیشاندان
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="pp-panel">
            <div class="pp-panel-head">لیستی کاڵاکان</div>
            <div class="p-0">
                <?php if (empty($products)): ?>
                    <div class="pp-empty">
                        <div class="pp-empty-icon" style="background:color-mix(in srgb,#10b981 14%,white);color:#059669">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <h3>هیچ کاڵایەک نییە!</h3>
                        <p>لەم کەتەگۆرییەدا هیچ کاڵایەک نەدۆزرایەوە. ئەمە هەواڵێکی باشە!</p>
                        <a href="<?php echo url('user/products/index.php'); ?>" class="pp-btn pp-btn-primary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ هەموو کاڵاکان
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 products-expired-table">
                            <thead>
                                <tr>
                                    <th>کاڵا</th>
                                    <th>کەتەلۆگ</th>
                                    <th>بەروارı بەسەرچوون</th>
                                    <th>دۆخ</th>
                                    <th>بەردەست</th>
                                    <th>نرخ</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $expiryDate = new DateTime($product['expiry_date']);
                                    $today = new DateTime();
                                    $daysUntilExpiry = $today->diff($expiryDate)->days;
                                    $isExpired = $expiryDate <= $today;
                                    
                                    // Status badge
                                    $statusClass = 'success';
                                    $statusText = 'باش';
                                    $statusIcon = 'check-circle';
                                    
                                    if ($isExpired) {
                                        $statusClass = 'danger';
                                        $statusText = 'بەسەرچووە';
                                        $statusIcon = 'x-circle';
                                    } elseif ($daysUntilExpiry <= 7) {
                                        $statusClass = 'warning';
                                        $statusText = 'زۆر نزیکە';
                                        $statusIcon = 'exclamation-triangle';
                                    } elseif ($daysUntilExpiry <= 30) {
                                        $statusClass = 'info';
                                        $statusText = 'نزیکە';
                                        $statusIcon = 'clock';
                                    } elseif ($daysUntilExpiry <= 60) {
                                        $statusClass = 'secondary';
                                        $statusText = 'نزیک (٢ مانگ)';
                                        $statusIcon = 'calendar-month';
                                    } elseif ($daysUntilExpiry <= 90) {
                                        $statusClass = 'dark';
                                        $statusText = 'نزیک (٣ مانگ)';
                                        $statusIcon = 'calendar-range';
                                    }
                                    ?>
                                    <?php
                                    $rowClass = 'exp-row';
                                    if ($isExpired) {
                                        $rowClass .= ' exp-row-expired';
                                    } elseif ($daysUntilExpiry <= 7) {
                                        $rowClass .= ' exp-row-week';
                                    } elseif ($daysUntilExpiry <= 30) {
                                        $rowClass .= ' exp-row-month';
                                    } elseif ($daysUntilExpiry <= 60) {
                                        $rowClass .= ' exp-row-2m';
                                    } elseif ($daysUntilExpiry <= 90) {
                                        $rowClass .= ' exp-row-3m';
                                    }
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td data-label="کاڵا">
                                            <div class="d-flex align-items-center">
                                                <?php if ($product['image_path']): ?>
                                                    <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                         alt="Product Image"
                                                         class="exp-prod-thumb me-2">
                                                <?php else: ?>
                                                    <div class="exp-prod-ph me-2">
                                                        <i class="bi bi-box-seam"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                                    <?php if ($product['barcode']): ?>
                                                        <br><small class="text-muted">
                                                            <code><?php echo htmlspecialchars($product['barcode']); ?></code>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="کەتەلۆگ">
                                            <?php echo $product['category_name'] ? htmlspecialchars($product['category_name']) : '-'; ?>
                                        </td>
                                        <td data-label="بەرواری بەسەرچوون">
                                            <div class="text-<?php echo $statusClass; ?>">
                                                <strong><?php echo $expiryDate->format('Y/m/d'); ?></strong>
                                            </div>
                                            <small class="text-muted">
                                                <?php
                                                if ($isExpired) {
                                                    echo $daysUntilExpiry . ' ڕۆژ لە پێش ئێستا';
                                                } else {
                                                    echo $daysUntilExpiry . ' ڕۆژی ماوە';
                                                }
                                                ?>
                                            </small>
                                        </td>
                                        <td data-label="دۆخ">
                                            <span class="badge bg-<?php echo $statusClass; ?>">
                                                <i class="bi bi-<?php echo $statusIcon; ?>"></i>
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td data-label="بەردەست">
                                            <span class="fw-bold"><?php echo number_format($product['stock_quantity']); ?></span>
                                            <small class="text-muted">دانە</small>
                                        </td>
                                        <td data-label="نرخ">
                                            <div class="text-success fw-bold">
                                                <?php echo formatMoney($product['sell_price']); ?>
                                            </div>
                                            <?php if ($product['buy_price'] > 0): ?>
                                                <small class="text-muted">
                                                    کڕین: <?php echo formatMoney($product['buy_price']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="<?php echo url('user/products/edit.php?id=' . $product['id']); ?>" 
                                                   class="btn btn-outline-primary" title="دەستکاری">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                
                                                <?php if ($product['stock_quantity'] > 0): ?>
                                                    <button type="button" class="btn btn-outline-warning" 
                                                            onclick="markAsRemoved(<?php echo $product['id']; ?>)" 
                                                            title="نیشانکردن وەک بەسەرچوو">
                                                        <i class="bi bi-archive"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="confirmDelete(<?php echo $product['id']; ?>)" 
                                                        title="سڕینەوە">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <?php
            // Build query string for pagination
            $queryParams = ['filter' => $filter];
            if ($filter === 'custom_days' && $customDays) {
                $queryParams['custom_days'] = $customDays;
            }
            $queryString = http_build_query($queryParams);
            ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo $queryString; ?>&page=<?php echo $page - 1; ?>">
                            <i class="bi bi-chevron-right"></i> پێشوو
                        </a>
                    </li>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo $queryString; ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo $queryString; ?>&page=<?php echo $page + 1; ?>">
                            دواتر <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

        <?php if (!empty($products)): ?>
            <div class="pp-panel mt-4">
                <div class="pp-panel-head"><i class="bi bi-gear"></i> کردارە گشتیەکان</div>
                <div class="pp-tips">
                    <div>
                        <h6 class="mb-2">پێشنیارەکان</h6>
                        <ul>
                            <li><i class="bi bi-check-circle text-success"></i> کاڵا بەسەرچووەکان بە خێرایی بسڕەوە یان بەردەستیان بکە بە سفر</li>
                            <li><i class="bi bi-exclamation-triangle text-warning"></i> کاڵا نزیک لە بەسەرچوونەکان بە داشکاندنەوە بفرۆشە</li>
                            <li><i class="bi bi-arrow-clockwise text-info"></i> بەروارەکانی بەسەرچوون بە دروستی دابنێ</li>
                        </ul>
                    </div>
                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm" onclick="printExpiredProducts()">
                            <i class="bi bi-printer"></i> چاپکردن
                        </button>
                        <button type="button" id="bulkUpdateBtn" class="pp-btn pp-btn-warn pp-btn-sm" onclick="bulkUpdateStock()">
                            <i class="bi bi-archive"></i> نوێکردنەوەی بەردەست
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
    <script>
        function confirmDelete(productId) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم کاڵایە؟')) {
                window.location.href = `<?php echo url('user/products/delete.php'); ?>?id=${productId}`;
            }
        }

        function markAsRemoved(productId) {
            if (confirm('ئایا دەتەوێت بەردەست بکەیت بە سفر؟ ئەمە وا دەکات کاڵاکە وەک تەواو بووە نیشان بدرێت.')) {
                // Update stock to 0
                fetch(`<?php echo url('api/products.php'); ?>?action=update&id=${productId}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        stock_quantity: 0
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('هەڵەیەک ڕوویدا: ' + (data.message || 'نامۆ'));
                    }
                })
                .catch(error => {
                   
                });
            }
        }

        function exportExpiredProducts() {
            let exportUrl = `<?php echo url('user/reports/export.php'); ?>?type=expired_products&filter=<?php echo $filter; ?>`;
            <?php if ($filter === 'custom_days' && $customDays): ?>
            exportUrl += `&custom_days=<?php echo $customDays; ?>`;
            <?php endif; ?>
            window.open(exportUrl, '_blank');
        }

        function printExpiredProducts() {
            window.print();
        }

        const expiredProductIds = <?php echo json_encode($expiredIdsForBulk, JSON_UNESCAPED_UNICODE); ?>;

        async function bulkUpdateStock() {
            if (!expiredProductIds.length) {
                alert('هیچ کاڵایەکی بەسەرچوو نییە بۆ نوێکردنەوە.');
                return;
            }

            if (!confirm(`ئایا دەتەوێت بەردەستی ${expiredProductIds.length} کاڵای بەسەرچوو بکەیت بە سفر؟ ئەمە وا دەکات لە بەشی بەسەرچووەکان دەربچن.`)) {
                return;
            }

            const btn = document.getElementById('bulkUpdateBtn');
            const originalHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> چاوەڕوان بە...';
            }

            let success = 0;
            let failed = 0;

            for (const id of expiredProductIds) {
                try {
                    const response = await fetch(`<?php echo url('api/products.php'); ?>?action=update&id=${id}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ stock_quantity: 0 })
                    });
                    const data = await response.json();
                    if (data.success) {
                        success++;
                    } else {
                        failed++;
                    }
                } catch (error) {
                    failed++;
                }

                if (btn) {
                    btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${success + failed}/${expiredProductIds.length}`;
                }
            }

            alert(`تەواوبوو: ${success} کاڵا نوێکرایەوە` + (failed ? `، ${failed} هەڵە` : ''));

            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }

            location.reload();
        }

        // Auto-refresh every 5 minutes
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                location.reload();
            }
        }, 300000);

        // Add visual indicators for urgency
        document.addEventListener('DOMContentLoaded', function() {
            const expiredRows = document.querySelectorAll('.exp-row-expired');
            expiredRows.forEach(row => {
                row.style.animation = 'none';
            });
        });

        const style = document.createElement('style');
        style.textContent = `
            @media print {
                .no-print, .pp-actions, .pp-chips, .pp-tips, .btn-group, .pagination { display: none !important; }
                .pp-hero, .pp-panel { border: none !important; box-shadow: none !important; }
                .exp-row-expired { background-color: #fee2e2 !important; }
                .exp-row-week { background-color: #fef3c7 !important; }
                .exp-row-month { background-color: #dbeafe !important; }
                .exp-row-2m { background-color: #ede9fe !important; }
                .exp-row-3m { background-color: #ffedd5 !important; }

                html[data-bs-theme='dark'] body,
                html[data-bs-theme='dark'] .pp-panel,
                html[data-bs-theme='dark'] .table,
                html[data-bs-theme='dark'] .table th,
                html[data-bs-theme='dark'] .table td {
                    background: #ffffff !important;
                    color: #000000 !important;
                    border-color: #d1d5db !important;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>