<?php
/**
 * My Orders - web/my-orders.php
 * Customer orders management page
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'auth/session_helper.php';

// Check if customer is logged in
CustomerSession::requireLogin('my-orders.php');

$customerData = CustomerSession::getCustomerData();
$customerId = $customerData['id'];

// Get filter
$statusFilter = $_GET['status'] ?? 'all';
$statusFilter = in_array($statusFilter, ['all', 'pending', 'completed']) ? $statusFilter : 'all';

// Get shop slug from URL parameter (optional)
$shopSlugParam = $_GET['shop'] ?? '';

// Build query
$whereClause = "wo.customer_id = ?";
$params = [$customerId];
$paramTypes = "i";

if ($statusFilter !== 'all') {
    $whereClause .= " AND wo.status = ?";
    $params[] = $statusFilter;
    $paramTypes .= "s";
}

// Get orders
$ordersStmt = $conn->prepare("
    SELECT wo.*, u.business_name, u.phone as shop_phone, u.address as shop_address
    FROM web_orders wo
    INNER JOIN users u ON wo.user_id = u.id
    WHERE $whereClause
    ORDER BY wo.created_at DESC
");
$ordersStmt->bind_param($paramTypes, ...$params);
$ordersStmt->execute();
$result = $ordersStmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);
$ordersStmt->close();

// Get most recent website_slug for back button
// Priority: 1) URL parameter, 2) Most recent order, 3) Empty (goes to main shop list)
$recentSlug = '';
if (!empty($shopSlugParam)) {
    $recentSlug = $shopSlugParam;
} elseif (!empty($orders)) {
    $recentSlug = $orders[0]['website_slug'];
}

// Get counts for tabs
$countStmt = $conn->prepare("
    SELECT status, COUNT(*) as count 
    FROM web_orders 
    WHERE customer_id = ? 
    GROUP BY status
");
$countStmt->bind_param("i", $customerId);
$countStmt->execute();
$countResult = $countStmt->get_result();

$allCount = 0;
$pendingCount = 0;
$completedCount = 0;

while ($row = $countResult->fetch_assoc()) {
    $allCount += $row['count'];
    if ($row['status'] === 'pending') {
        $pendingCount = $row['count'];
    } elseif ($row['status'] === 'completed') {
        $completedCount = $row['count'];
    }
}
$countStmt->close();

// Format price by currency (IQD = دینار, USD = دۆلار)
function formatPriceOrder($price, $currency = 'IQD') {
    if ($price === null || $price === '') {
        return ($currency === 'USD') ? '0 دۆلار' : '0 دینار';
    }
    $curr = $currency === 'USD' ? 'USD' : 'IQD';
    $decimals = ($curr === 'USD') ? 2 : 0;
    $formatted = number_format((float)$price, $decimals, '.', ',');
    return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داواکارییەکانم - فرۆشگای ئۆنلاین</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="template/assets/css/shop.css" rel="stylesheet">
    
    <style>
        .orders-container {
            min-height: 100vh;
            background: #f8f9fa;
        }
        
        .order-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .order-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
        }
        
        .order-number {
            font-size: 1.3rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .order-date {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .order-body {
            padding: 1.5rem;
        }
        
        .order-status {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .order-items {
            margin-bottom: 1rem;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-total {
            background: #28a745;
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            font-size: 1.1rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }
        
        .order-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .nav-tabs .nav-link {
            color: #6c757d;
            border: none;
            padding: 0.75rem 1.5rem;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            background: white;
            border-bottom: 3px solid #667eea;
        }
        
        .badge-count {
            background: #dc3545;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body class="orders-container">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>web/<?php echo $recentSlug ? htmlspecialchars($recentSlug) . '/' : ''; ?>">
                <i class="bi bi-arrow-right"></i>
                گەڕانەوە بۆ فرۆشگا
            </a>

            <div class="navbar-nav ms-auto">
                <span class="navbar-text">
                    <i class="bi bi-person-circle"></i>
                    <?php echo htmlspecialchars($customerData['name']); ?>
                </span>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">داواکارییەکانم</h2>
                        <p class="text-muted mb-0">مێژووی هەموو داواکارییەکانت</p>
                    </div>
                    <div>
                        <a href="<?php echo SITE_URL; ?>web/<?php echo $recentSlug ? htmlspecialchars($recentSlug) . '/' : ''; ?>" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i> گەڕانەوە بۆ فرۆشگا
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $statusFilter === 'all' ? 'active' : ''; ?>"
                   href="?status=all<?php echo $shopSlugParam ? '&shop=' . urlencode($shopSlugParam) : ''; ?>">
                    <i class="bi bi-list-ul"></i>
                    هەموو داواکارییەکان
                    <?php if ($allCount > 0): ?>
                        <span class="badge badge-count"><?php echo $allCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>"
                   href="?status=pending<?php echo $shopSlugParam ? '&shop=' . urlencode($shopSlugParam) : ''; ?>">
                    <i class="bi bi-hourglass-split"></i>
                    ناردراوەکان
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge badge-count"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>"
                   href="?status=completed<?php echo $shopSlugParam ? '&shop=' . urlencode($shopSlugParam) : ''; ?>">
                    <i class="bi bi-check-circle"></i>
                    تەواو بووەکان
                    <?php if ($completedCount > 0): ?>
                        <span class="badge bg-secondary"><?php echo $completedCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <!-- Orders List -->
        <div class="row">
            <?php if (empty($orders)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h4 class="mt-3">هیچ داواکارییەک نییە</h4>
                    <p class="text-muted">
                        <?php if ($statusFilter === 'pending'): ?>
                            هیچ داواکارییەکی ناردراو نییە
                        <?php elseif ($statusFilter === 'completed'): ?>
                            هیچ داواکارییەکی تەواو بوو نییە
                        <?php else: ?>
                            هێشتا هیچ داواکارییەکت نەکردووە
                        <?php endif; ?>
                    </p>
                    <a href="<?php echo SITE_URL; ?>web/<?php echo $recentSlug ? htmlspecialchars($recentSlug) . '/' : ''; ?>" class="btn btn-primary">
                        <i class="bi bi-cart-plus"></i> دەستپێکردنی کڕین
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $order): 
                    $items = json_decode($order['items'], true);
                    $orderDate = date('Y/m/d H:i', strtotime($order['created_at']));
                    $statusClass = $order['status'] === 'completed' ? 'status-completed' : 'status-pending';
                    $statusText = $order['status'] === 'completed' ? 'تەواو بووە' : 'ناردراوە';
                ?>
                <div class="col-lg-6 col-xl-4">
                    <div class="order-card">
                        <div class="order-header">
                            <div class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></div>
                            <div class="order-date"><?php echo $orderDate; ?></div>
                        </div>
                        
                        <div class="order-body">
                            <div class="order-status <?php echo $statusClass; ?>">
                                <i class="bi bi-<?php echo $order['status'] === 'completed' ? 'check-circle' : 'hourglass-split'; ?>"></i>
                                <?php echo $statusText; ?>
                            </div>
                            
                            <!-- Shop Info -->
                            <div class="mb-3">
                                <strong>فرۆشگا:</strong> <?php echo htmlspecialchars($order['business_name']); ?><br>
                                <?php if (!empty($order['shop_phone'])): ?>
                                <small class="text-muted">
                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($order['shop_phone']); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Items -->
                            <div class="order-items">
                                <h6 class="mb-2">کاڵاکان:</h6>
                                <?php foreach ($items as $item):
                                    $itemCurrency = $item['currency'] ?? 'IQD';
                                    // Calculate subtotal if not present
                                    $subtotal = isset($item['subtotal']) ? $item['subtotal'] : ($item['price'] * $item['quantity']);
                                ?>
                                <div class="order-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                        <small class="text-muted">
                                            <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'دانە'); ?> ×
                                            <?php echo formatPriceOrder($item['price'], $itemCurrency); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <strong><?php echo formatPriceOrder($subtotal, $itemCurrency); ?></strong>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if (!empty($order['notes'])): ?>
                            <div class="alert alert-info py-2 mb-3">
                                <small><i class="bi bi-chat-left-text"></i> <?php echo nl2br(htmlspecialchars($order['notes'])); ?></small>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Total -->
                            <?php 
                            $totalIQD = 0;
                            $totalUSD = 0;
                            if (is_array($items)) {
                                foreach ($items as $item) {
                                    $amt = isset($item['subtotal']) ? $item['subtotal'] : ($item['price'] * $item['quantity']);
                                    $curr = $item['currency'] ?? 'IQD';
                                    if ($curr === 'USD') $totalUSD += $amt;
                                    else $totalIQD += $amt;
                                }
                            }
                            $orderHasBoth = $totalIQD > 0 && $totalUSD > 0;
                            ?>
                            <div class="order-total">
                                <?php if ($orderHasBoth): ?>
                                    کۆی دینار: <?php echo formatPriceOrder($totalIQD, 'IQD'); ?><br>
                                    کۆی دۆلار: <?php echo formatPriceOrder($totalUSD, 'USD'); ?>
                                <?php elseif ($totalIQD > 0): ?>
                                    کۆی گشتی: <?php echo formatPriceOrder($totalIQD, 'IQD'); ?>
                                <?php else: ?>
                                    کۆی گشتی: <?php echo formatPriceOrder($totalUSD, 'USD'); ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Actions -->
                            <div class="order-actions">
                                <a href="<?php echo SITE_URL; ?>web/api/generate_order_pdf.php?order_id=<?php echo $order['id']; ?>" 
                                   target="_blank" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-file-pdf"></i> دابەزاندنی وەسڵ
                                </a>
                                
                                <?php if ($order['status'] === 'completed' && !empty($order['completed_at'])): ?>
                                <small class="text-muted">
                                    <i class="bi bi-check-circle"></i>
                                    تەواو بووە لە: <?php echo date('Y/m/d H:i', strtotime($order['completed_at'])); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
