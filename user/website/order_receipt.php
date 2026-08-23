<?php
/**
 * وەسڵی داواکاری - user/website/order_receipt.php
 * وەسڵێکی مۆدێرن بۆ داواکارییەکانی فرۆشگای ئۆنلاین
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// Check user authentication
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Check if user is main user (not sub-user)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    header('Location: ' . url('user/dashboard/index.php'));
    exit;
}

$orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$orderId) {
    header('Location: ' . url('user/website/orders.php'));
    exit;
}

// Get order details
$stmt = $conn->prepare("
    SELECT * FROM web_orders 
    WHERE id = ? AND user_id = ?
");
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$order = $result->fetch_assoc();
$stmt->close();

if (!$order) {
    header('Location: ' . url('user/website/orders.php'));
    exit;
}

$items = json_decode($order['items'], true);
$orderDate = date('Y/m/d H:i', strtotime($order['created_at']));
$shortDate = date('d/m/Y', strtotime($order['created_at']));
$time = date('H:i', strtotime($order['created_at']));

// Get settings
$settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param('i', $userId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();

$pageTitle = "وەسڵی داواکاری - " . $order['order_number'];
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    
    <style>
        @media print {
            body * { visibility: hidden; }
            .receipt-container, .receipt-container * { visibility: visible; }
            .receipt-container { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 100%; 
            }
            .no-print { display: none !important; }
            .receipt { 
                border: none !important; 
                box-shadow: none !important;
                page-break-inside: avoid;
            }
            @page {
                margin: 1cm;
            }

            /* چاپ هەمیشە light بێت لە order receipt */
            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .receipt,
            html[data-bs-theme='dark'] .customer-info,
            html[data-bs-theme='dark'] .receipt-total,
            html[data-bs-theme='dark'] .items-table tbody tr:nth-child(even),
            html[data-bs-theme='dark'] .status-badge {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #000000 !important;
                box-shadow: none !important;
            }

            html[data-bs-theme='dark'] .items-table th,
            html[data-bs-theme='dark'] .items-table thead,
            html[data-bs-theme='dark'] .items-table td,
            html[data-bs-theme='dark'] .receipt * {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #000000 !important;
            }
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .receipt {
            max-width: 70mm;
            width: 70mm;
            margin: 0 auto;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            border: 2px solid #333;
            padding: 8px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-size: 10px;
        }
        
        /* بەشی سەرەوە - ناوی شوێن */
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .receipt-header h1 {
            font-size: 16px;
            font-weight: bold;
            color: #000;
            margin: 0;
            padding: 5px 0;
        }
        
        .receipt-header .business-info {
            font-size: 9px;
            color: #000;
            margin-top: 5px;
        }
        
        /* بەشی دووەم - زانیاری کڕیار */
        .customer-info {
            background: #e9ecef;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 2px solid #000;
        }
        
        .customer-info h3 {
            font-size: 12px;
            font-weight: bold;
            color: #000;
            margin: 0 0 8px 0;
            padding-bottom: 5px;
            border-bottom: 2px solid #000;
        }
        
        .customer-info .info-row {
            display: flex;
            padding: 3px 0;
            font-size: 10px;
        }
        
        .customer-info .info-label {
            font-weight: bold;
            color: #000;
            min-width: 60px;
        }
        
        .customer-info .info-value {
            color: #000;
            word-break: break-word;
        }
        
        /* بەشی سێیەم - خشتەی کاڵاکان */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .items-table thead {
            background: #000;
            color: white;
        }
        
        .items-table th {
            padding: 5px 2px;
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            border: 2px solid #000;
        }
        
        .items-table tbody tr {
            border-bottom: 2px solid #333;
        }
        
        .items-table tbody tr:nth-child(even) {
            background: #e9ecef;
        }
        
        .items-table td {
            padding: 5px 2px;
            text-align: center;
            font-size: 9px;
            color: #000;
            border: 1px solid #333;
            word-break: break-word;
        }
        
        .items-table td:first-child {
            text-align: right;
            font-weight: 600;
            font-size: 10px;
        }
        
        /* کۆی گشتی */
        .receipt-total {
            background: #e9ecef;
            padding: 8px;
            border-radius: 4px;
            border: 2px solid #000;
        }
        
        .receipt-total .total-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 10px;
            border-bottom: 2px solid #000;
            color: #000;
        }
        
        .receipt-total .total-row:last-child {
            border-bottom: none;
            border-top: 3px solid #000;
            margin-top: 5px;
            padding-top: 8px;
        }
        
        .receipt-total .total-row strong {
            font-weight: bold;
            color: #000;
        }
        
        .receipt-total .final-total {
            font-size: 12px;
            color: #000;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #000;
            font-size: 9px;
            color: #000;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
            margin-top: 5px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        @media print {
            .receipt {
                font-size: 10px;
                border: none;
                box-shadow: none;
            }
            .items-table th,
            .items-table td {
                font-size: 8px;
            }
            @page {
                size: 70mm auto;
                margin: 0;
            }
        }

        .action-buttons {
            text-align: center;
            margin: 20px 0;
        }

        .action-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s;
            margin: 0 5px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .btn-print {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
        }

        .btn-back {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
        }
    </style>
</head>
<body class="website-module-page website-receipt-page bg-light">
    <!-- Action Buttons -->
    <div class="action-buttons no-print">
        <button class="action-btn btn-print" onclick="window.print()">
            <i class="bi bi-printer"></i>
            چاپکردن
        </button>
        <button class="action-btn btn-back" onclick="window.close()">
            <i class="bi bi-x-circle"></i>
            داخستن
        </button>
    </div>
    
    <!-- Receipt Container -->
    <div class="receipt-container">
        <div class="receipt">
            <!-- بەشی یەکەم: ناوی شوێن/فرۆشگا -->
            <div class="receipt-header">
                <?php if ($settings && $settings['business_logo']): ?>
                    <img src="<?php echo url('uploads/' . $settings['business_logo']); ?>" 
                         alt="Logo" style="max-width: 100px; max-height: 100px; margin-bottom: 15px;">
                <?php endif; ?>
                
                <h1><?php echo htmlspecialchars($currentUser['business_name']); ?></h1>
                
                <?php if ($settings && $settings['receipt_header']): ?>
                    <div class="business-info">
                        <?php echo nl2br(htmlspecialchars($settings['receipt_header'])); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- بەشی دووەم: زانیاری کڕیار و بەروار -->
            <div class="customer-info">
                <h3>زانیاری وەسڵ</h3>
                
                <div class="info-row">
                    <span class="info-label">ناوی کڕیار:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                </div>
                
                <?php if (!empty($order['customer_address'])): ?>
                <div class="info-row">
                    <span class="info-label">ناونیشان:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['customer_address']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($order['customer_phone'])): ?>
                <div class="info-row">
                    <span class="info-label">ژمارە مۆبایل:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="info-row">
                    <span class="info-label">بەرواری داواکاری:</span>
                    <span class="info-value"><?php echo $shortDate; ?> - <?php echo $time; ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">ژمارەی وەسڵ:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>

                <div class="info-row">
                    <span class="info-label">دۆخی داواکاری:</span>
                    <span class="info-value">
                        <span class="status-badge status-<?php echo $order['status']; ?>">
                            <?php 
                            switch($order['status']) {
                                case 'pending': echo 'چاوەڕوانی'; break;
                                case 'completed': echo 'تەواوبووە'; break;
                                case 'cancelled': echo 'هەڵوەشاوەتەوە'; break;
                                default: echo $order['status']; break;
                            }
                            ?>
                        </span>
                    </span>
                </div>
            </div>
            
            <!-- بەشی سێیەم: خشتەی کاڵاکان -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th>ناوی کاڵا</th>
                        <th>یەکە</th>
                        <th>نرخ</th>
                        <th>کۆی گشتی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): 
                        // Format quantity
                        $rawQty = is_string($item['quantity']) ? $item['quantity'] : (string)$item['quantity'];
                        if (is_numeric($rawQty)) {
                            $normalizedQty = number_format((float)$rawQty, 6, '.', '');
                            $compactQty = rtrim(rtrim($normalizedQty, '0'), '.');
                        } else {
                            $compactQty = $rawQty;
                        }
                        
                        // Calculate item total
                        $itemTotal = $item['quantity'] * $item['price'];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td>
                                <?php echo $compactQty; ?>
                                <?php if (!empty($item['unit'])): ?>
                                    <?php echo htmlspecialchars($item['unit']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($item['price'], 0); ?> د</td>
                            <td><strong><?php echo number_format($itemTotal, 0); ?> د</strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- کۆی گشتی -->
            <div class="receipt-total">
                <div class="total-row">
                    <strong class="final-total">کۆی کۆتایی:</strong>
                    <strong class="final-total"><?php echo number_format($order['total_amount'], 0); ?> دینار</strong>
                </div>
            </div>

            <?php if (!empty($order['notes'])): ?>
            <div style="margin-top: 10px; padding: 8px; background: #fff8e1; border-radius: 4px; border: 1px solid #ffc107;">
                <div style="font-weight: bold; font-size: 10px; margin-bottom: 5px;">تێبینی:</div>
                <div style="font-size: 9px;"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="receipt-footer">
                <?php if ($settings && $settings['receipt_footer']): ?>
                    <div style="margin-bottom: 15px;">
                        <?php echo nl2br(htmlspecialchars($settings['receipt_footer'])); ?>
                    </div>
                <?php endif; ?>
                
                <div style="font-size: 16px; font-weight: 600; color: #000; margin: 15px 0;">
                    ✨ سپاس بۆ کڕینەکەت! ✨
                </div>
                
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <strong>سیستەمی NexoraCore</strong>
                    <br>
                    <span style="color: #666;">nexoracore.com</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto print if print parameter is present
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === '1') {
            setTimeout(() => {
                window.print();
            }, 1000);
        }
    </script>
</body>
</html>

