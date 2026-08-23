<?php
/**
 * Guest Orders - web/my-orders-guest.php
 * Shows orders for guest customers based on session and IP
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'auth/session_helper.php';

// Start session
CustomerSession::start();

// Get guest session ID and IP
$guestSessionId = CustomerSession::getGuestSessionId();
$guestIpAddress = CustomerSession::getClientIp();

// Get filter
$statusFilter = $_GET['status'] ?? 'all';
$statusFilter = in_array($statusFilter, ['all', 'pending', 'completed']) ? $statusFilter : 'all';

// Get shop slug from URL parameter (optional)
$shopSlugParam = $_GET['shop'] ?? '';

// Get orders for this guest
$orders = [];
if ($guestSessionId) {
    $orders = CustomerSession::getGuestOrders($guestSessionId, $guestIpAddress);
    
    // Apply status filter
    if ($statusFilter !== 'all') {
        $orders = array_filter($orders, function($order) use ($statusFilter) {
            return $order['status'] === $statusFilter;
        });
    }
}

// Helper function to format price by currency (IQD = دینار, USD = دۆلار)
function formatPrice($price, $currency = 'IQD') {
    if ($price === null || $price === '') {
        return ($currency === 'USD') ? '0 دۆلار' : '0 دینار';
    }
    $curr = $currency === 'USD' ? 'USD' : 'IQD';
    $decimals = ($curr === 'USD') ? 2 : 0;
    $formatted = number_format((float)$price, $decimals, '.', ',');
    return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
}

// Helper function to get status badge
function getStatusBadge($status) {
    switch ($status) {
        case 'pending':
            return '<span class="badge bg-warning text-dark">چاوەڕوان</span>';
        case 'completed':
            return '<span class="badge bg-success">تەواو بووە</span>';
        case 'cancelled':
            return '<span class="badge bg-danger">هەڵوەشاوەتەوە</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داواکارییەکانی میوان - فرۆشگای ئۆنلاین</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/shop.css" rel="stylesheet">
    
    <style>
        .orders-container {
            min-height: 100vh;
            background: #f8f9fa;
        }
        
        .order-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.2s;
        }
        
        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .order-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .order-body {
            padding: 1.5rem;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .filter-tabs {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .info-alert {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
        }
        
        .info-alert .alert-heading {
            color: white;
        }
    </style>
</head>
<body class="orders-container">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>web/<?php echo $shopSlugParam ? htmlspecialchars($shopSlugParam) . '/' : ''; ?>">
                <i class="bi bi-arrow-right"></i>
                <?php echo $shopSlugParam ? 'گەڕانەوە بۆ فرۆشگا' : 'گەڕانەوە بۆ لیستی فرۆشگاکان'; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <span class="navbar-text">
                    <i class="bi bi-person"></i>
                    داواکارییەکانی میوان
                </span>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">
                    <i class="bi bi-list-ul"></i>
                    داواکارییەکانی من
                </h2>
                
                <?php if (!$guestSessionId): ?>
                    <div class="alert info-alert">
                        <h5 class="alert-heading">
                            <i class="bi bi-info-circle"></i>
                            بەخێربێیت بۆ لاپەڕەی داواکارییەکانی میوان
                        </h5>
                        <p class="mb-0">
                            لێرە دەتوانیت داواکارییە پێشووەکانی خۆت ببینیتەوە کە لەم براوزەرە کراون.
                            ئەگەر هیچ داواکارییەکت نییە، دەتوانیت بچیتە فرۆشگاکان و کاڵا بکڕیت.
                        </p>
                    </div>
                <?php endif; ?>
                
                <!-- Filter Tabs -->
                <?php if (!empty($orders)): ?>
                <div class="filter-tabs">
                    <div class="btn-group" role="group">
                        <a href="?status=all<?php echo $shopSlugParam ? '&shop=' . urlencode($shopSlugParam) : ''; ?>" 
                           class="btn <?php echo $statusFilter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="bi bi-grid"></i> هەموو
                        </a>
                        <a href="?status=pending<?php echo $shopSlugParam ? '&shop=' . urlencode($shopSlugParam) : ''; ?>" 
                           class="btn <?php echo $statusFilter === 'pending' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="bi bi-clock"></i> چاوەڕوان
                        </a>
                        <a href="?status=completed<?php echo $shopSlugParam ? '&shop=' . urlencode($shopSlugParam) : ''; ?>" 
                           class="btn <?php echo $statusFilter === 'completed' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="bi bi-check-circle"></i> تەواو بووە
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Orders List -->
                <?php if (empty($orders)): ?>
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                        </div>
                        <h4 class="mb-3">هیچ داواکارییەک نەدۆزرایەوە</h4>
                        <p class="text-muted mb-4">
                            <?php if ($statusFilter !== 'all'): ?>
                                هیچ داواکارییەکی "<?php echo $statusFilter === 'pending' ? 'چاوەڕوان' : 'تەواو بووە'; ?>" نییە
                            <?php else: ?>
                                هێشتا هیچ داواکارییەکت نەکردووە لەم براوزەرە
                            <?php endif; ?>
                        </p>
                        <a href="<?php echo SITE_URL; ?>web/" class="btn btn-primary">
                            <i class="bi bi-shop"></i>
                            چوون بۆ فرۆشگاکان
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <?php 
                            $items = json_decode($order['items'], true);
                            $itemCount = is_array($items) ? count($items) : 0;
                        ?>
                        <div class="order-card">
                            <div class="order-header">
                                <div>
                                    <h5 class="mb-0">
                                        <i class="bi bi-receipt"></i>
                                        داواکاری #<?php echo htmlspecialchars($order['order_number']); ?>
                                    </h5>
                                    <small><?php echo date('Y/m/d - h:i A', strtotime($order['created_at'])); ?></small>
                                </div>
                                <div>
                                    <?php echo getStatusBadge($order['status']); ?>
                                </div>
                            </div>
                            
                            <div class="order-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-2">
                                            <i class="bi bi-shop"></i>
                                            فرۆشگا
                                        </h6>
                                        <p class="mb-0"><?php echo htmlspecialchars($order['business_name']); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-2">
                                            <i class="bi bi-person"></i>
                                            زانیاری کڕیار
                                        </h6>
                                        <p class="mb-0">
                                            <?php echo htmlspecialchars($order['customer_name']); ?><br>
                                            <small><?php echo htmlspecialchars($order['customer_phone']); ?></small>
                                        </p>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <h6 class="mb-3">
                                    <i class="bi bi-box-seam"></i>
                                    کاڵاکان (<?php echo $itemCount; ?>)
                                </h6>
                                
                                <?php if (is_array($items)): ?>
                                    <?php foreach ($items as $item): 
                                        $itemCurrency = $item['currency'] ?? 'IQD';
                                    ?>
                                        <div class="order-item">
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                                <small class="text-muted">
                                                    <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'دانە'); ?> 
                                                    × <?php echo formatPrice($item['price'], $itemCurrency); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <strong><?php echo formatPrice($item['price'] * $item['quantity'], $itemCurrency); ?></strong>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php 
                                $totalIQD = 0;
                                $totalUSD = 0;
                                if (is_array($items)) {
                                    foreach ($items as $item) {
                                        $amt = $item['price'] * $item['quantity'];
                                        $curr = $item['currency'] ?? 'IQD';
                                        if ($curr === 'USD') $totalUSD += $amt;
                                        else $totalIQD += $amt;
                                    }
                                }
                                $orderHasBoth = $totalIQD > 0 && $totalUSD > 0;
                                ?>
                                <div class="mt-3 pt-3 border-top">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <?php if ($orderHasBoth): ?>
                                            <div>
                                                <h5 class="mb-1">کۆی دینار:</h5>
                                                <h5 class="mb-0">کۆی دۆلار:</h5>
                                            </div>
                                            <div class="text-end">
                                                <h4 class="mb-1 text-success"><?php echo formatPrice($totalIQD, 'IQD'); ?></h4>
                                                <h4 class="mb-0 text-success"><?php echo formatPrice($totalUSD, 'USD'); ?></h4>
                                            </div>
                                        <?php elseif ($totalIQD > 0): ?>
                                            <h5 class="mb-0">کۆی گشتی:</h5>
                                            <h4 class="mb-0 text-success"><?php echo formatPrice($totalIQD, 'IQD'); ?></h4>
                                        <?php else: ?>
                                            <h5 class="mb-0">کۆی گشتی:</h5>
                                            <h4 class="mb-0 text-success"><?php echo formatPrice($totalUSD, 'USD'); ?></h4>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($order['notes'])): ?>
                                    <div class="mt-3 pt-3 border-top">
                                        <h6 class="text-muted mb-2">
                                            <i class="bi bi-chat-left-text"></i>
                                            تێبینی
                                        </h6>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['customer_address'])): ?>
                                    <div class="mt-3 pt-3 border-top">
                                        <h6 class="text-muted mb-2">
                                            <i class="bi bi-geo-alt"></i>
                                            ناونیشان
                                        </h6>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($order['customer_address'])); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0">
                <i class="bi bi-shield-check"></i>
                پاڵپشتی لەلایەن <?php echo SITE_NAME; ?>
            </p>
            <p class="mb-0 small text-muted mt-2">
                <i class="bi bi-info-circle"></i>
                داواکارییەکانت تەنها لەم براوزەرە دەبینرێن
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

