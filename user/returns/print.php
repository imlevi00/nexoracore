<?php
/**
 * Print Return Receipt - user/returns/print.php
 * Print return receipt
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی ID ی گەڕاندنەوە
$returnId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$returnId) {
    header('Location: index.php');
    exit();
}

// وەرگرتنی زانیاری گەڕاندنەوە
$returnQuery = "
    SELECT r.*, c.name as customer_name
    FROM returns r
    LEFT JOIN customers c ON r.customer_id = c.id
    WHERE r.id = ? AND r.user_id = ?
";

$stmt = $conn->prepare($returnQuery);
$stmt->bind_param('ii', $returnId, $userId);
$stmt->execute();
$return = $stmt->get_result()->fetch_assoc();

if (!$return) {
    header('Location: index.php');
    exit();
}

// وەرگرتنی کاڵاکانی گەڕاندنەوە
$itemsQuery = "
    SELECT ri.*
    FROM return_items ri
    WHERE ri.return_id = ?
    ORDER BY ri.id
";

$stmt = $conn->prepare($itemsQuery);
$stmt->bind_param('i', $returnId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی ڕێکخستنەکان
$settingsQuery = "SELECT * FROM settings WHERE user_id = $userId";
$settingsResult = $conn->query($settingsQuery);
$settings = [];
while ($row = $settingsResult->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// دروستکردنی ناوی فایل بۆ چاپکردن
$filename = 'return_receipt_' . $return['return_number'] . '.html';
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پسوولەی گەڕاندنەوە - <?php echo htmlspecialchars($return['return_number']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            padding: 20px;
        }
        
        .receipt {
            max-width: 300px;
            margin: 0 auto;
            border: 1px solid #000;
            padding: 15px;
        }
        
        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .header h1 {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 10px;
            margin: 2px 0;
        }
        
        .return-info {
            margin-bottom: 15px;
        }
        
        .return-info p {
            margin: 3px 0;
            font-size: 11px;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .items-table th,
        .items-table td {
            padding: 3px 0;
            border-bottom: 1px dotted #ccc;
            font-size: 10px;
        }
        
        .items-table th {
            font-weight: bold;
            text-align: center;
            background: #f5f5f5;
        }
        
        .items-table .item-name {
            text-align: right;
            width: 50%;
        }
        
        .items-table .item-qty {
            text-align: center;
            width: 15%;
        }
        
        .items-table .item-price {
            text-align: left;
            width: 20%;
        }
        
        .items-table .item-total {
            text-align: left;
            width: 15%;
        }
        
        .totals {
            border-top: 2px solid #000;
            padding-top: 10px;
            margin-top: 15px;
        }
        
        .totals .total-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
            font-size: 11px;
        }
        
        .totals .final-total {
            font-weight: bold;
            font-size: 14px;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 10px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        
        .reason {
            margin: 10px 0;
            padding: 5px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            font-size: 10px;
        }
        
        @media print {
            body {
                padding: 0;
                background: #ffffff !important;
                color: #000000 !important;
            }
            
            .receipt {
                border: none;
                max-width: none;
                margin: 0;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .receipt,
            html[data-bs-theme='dark'] .receipt * {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #000000 !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- سەرپەڕە -->
        <div class="header">
            <h1><?php echo htmlspecialchars($settings['receipt_header'] ?? 'کۆمپانیا'); ?></h1>
            <p>پسوولەی گەڕاندنەوەی کاڵا</p>
            <p>بەروار: <?php echo date('Y/m/d H:i', strtotime($return['return_date'])); ?></p>
        </div>
        
        <!-- زانیاری گەڕاندنەوە -->
        <div class="return-info">
            <p><strong>ژمارەی گەڕاندنەوە:</strong> <?php echo htmlspecialchars($return['return_number']); ?></p>
            <?php if ($return['customer_name']): ?>
                <p><strong>کڕیار:</strong> <?php echo htmlspecialchars($return['customer_name']); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- هۆکاری گەڕاندنەوە -->
        <?php if (!empty($return['return_reason'])): ?>
            <div class="reason">
                <strong>هۆکاری گەڕاندنەوە:</strong><br>
                <?php echo nl2br(htmlspecialchars($return['return_reason'])); ?>
            </div>
        <?php endif; ?>
        
        <!-- خشتەی کاڵاکان -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="item-name">کاڵا</th>
                    <th class="item-qty">بڕ</th>
                    <th class="item-price">نرخ</th>
                    <th class="item-total">کۆی</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="item-name">
                            <?php echo htmlspecialchars($item['product_name']); ?>
                            <?php if ($item['unit_name']): ?>
                                <br><small>(<?php echo htmlspecialchars($item['unit_name']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="item-qty">
                            <?php echo number_format($item['quantity']); ?>
                            <?php if ($item['unit_symbol']): ?>
                                <?php echo htmlspecialchars($item['unit_symbol']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="item-price"><?php echo number_format($item['unit_price']); ?></td>
                        <td class="item-total"><?php echo number_format($item['total_price']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- کۆی گشتی -->
        <div class="totals">
            <div class="total-row">
                <span>کۆی گشتی:</span>
                <span><?php echo number_format($return['total_amount']); ?> دینار</span>
            </div>
            
            <?php if ($return['discount'] > 0): ?>
                <div class="total-row">
                    <span>داشکاندن:</span>
                    <span>-<?php echo number_format($return['discount']); ?> دینار</span>
                </div>
            <?php endif; ?>
            
            <div class="total-row final-total">
                <span>کۆی کۆتایی:</span>
                <span><?php echo number_format($return['final_amount']); ?> دینار</span>
            </div>
        </div>
        
        <!-- پێنووسەکانی خوارەوە -->
        <div class="footer">
            <p><strong>سپاس بۆ گەڕاندنەوە</strong></p>
            <p><?php echo htmlspecialchars($settings['receipt_footer'] ?? 'بەهیوای دووبارە کڕین'); ?></p>
            <p>چاپکراوە لە: <?php echo date('Y/m/d H:i:s'); ?></p>
            <p style="margin-top: 10px;"><strong>سیستەمی NexoraCore</strong></p>
            <p>nexoracore.com</p>
        </div>
    </div>
    
    <script>
        // چاپکردنی خۆکار لە کاتی کردنەوەی فایلەکە
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
