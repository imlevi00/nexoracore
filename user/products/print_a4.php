<?php
/**
 * چاپکردنی کاڵاکان بە فۆرماتی A4 - user/products/print_a4.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی فیلتەرەکان (ئەگەر هەبێت)
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$stock_filter = $_GET['filter'] ?? '';

// وەرگرتنی ID-کانی کاڵا هەڵبژێردراوەکان
$selectedProductIds = [];
if (!empty($_GET['products'])) {
    $selectedProductIds = array_map('intval', explode(',', $_GET['products']));
    $selectedProductIds = array_filter($selectedProductIds); // حذف مقادیر خالی
}

// وەرگرتنی ڕێکخستنەکانی دیزاین
$scale = 100; // Fixed at 100%

$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$per_page = max(5, min(30, $per_page)); // Clamp between 5-30

$layout = $_GET['layout'] ?? 'single';
$layout = in_array($layout, ['single', 'double']) ? $layout : 'single';

// وەرگرتنی ستونە هەڵبژێردراوەکان
$selectedColumnsParam = $_GET['cols'] ?? '';
$selectedColumns = [];
if (!empty($selectedColumnsParam)) {
    $selectedColumns = explode(',', $selectedColumnsParam);
    $selectedColumns = array_map('trim', $selectedColumns);
} else {
    // Default: show all columns except image
    $selectedColumns = ['name', 'category', 'barcode', 'unit', 'sell_price', 'wholesale_price', 'special_price', 'stock', 'date', 'status'];
}

// دروستکردنی array بۆ پشکنینی هەبوونی ستون
$columnsMap = [
    'name' => true,
    'image' => in_array('image', $selectedColumns),
    'category' => in_array('category', $selectedColumns),
    'barcode' => in_array('barcode', $selectedColumns),
    'unit' => in_array('unit', $selectedColumns),
    'sell_price' => in_array('sell_price', $selectedColumns),
    'wholesale_price' => in_array('wholesale_price', $selectedColumns),
    'special_price' => in_array('special_price', $selectedColumns),
    'stock' => in_array('stock', $selectedColumns),
    'date' => in_array('date', $selectedColumns),
    'status' => in_array('status', $selectedColumns)
];

// دروستکردنی WHERE clause
$whereConditions = ["p.user_id = $userId"];
$searchParams = [];

// ئەگەر کاڵا هەڵبژێردراوەکان هەبن، تەنها ئەوان نیشان بدە
if (!empty($selectedProductIds)) {
    // اگر کالاهای انتخاب‌شده وجود دارد، فقط آنها را نمایش بده
    $placeholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
    $whereConditions[] = "p.id IN ($placeholders)";
    $searchParams = array_merge($searchParams, $selectedProductIds);
    // نادیده گرفتن فیلترهای دیگر اگر کالاهای انتخاب‌شده وجود دارد
    $search = '';
    $category_filter = '';
    $stock_filter = '';
    $stockCondition = '';
} else {
    // استفاده از فیلترهای موجود اگر کالاهای انتخاب‌شده وجود ندارد
    if (!empty($search)) {
        $whereConditions[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
        $searchTerm = "%$search%";
        $searchParams[] = $searchTerm;
        $searchParams[] = $searchTerm;
    }

    if (!empty($category_filter) && is_numeric($category_filter)) {
        $whereConditions[] = "p.category_id = ?";
        $searchParams[] = $category_filter;
    }

    // فلتەری stock — پێناسەی «تەواوبوو» = هیچ یەکەیەک بڕی بەردەستی > 0 نییە (هەمان لۆجیکی index.php)،
    // بۆ ئەوەی کاڵای خاوەن بەردەست بە هەڵە وەک تەواوبوو دەرنەکەوێت.
    $hasPositiveStockExpr = "EXISTS (
        SELECT 1 FROM product_units pu_pos
        WHERE pu_pos.product_id = p.id AND pu_pos.stock_quantity > 0
    )";
    $primaryStockExpr = "COALESCE(
        (SELECT pu_primary.stock_quantity FROM product_units pu_primary
         WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
         ORDER BY pu_primary.id ASC LIMIT 1),
        (SELECT pu_any.stock_quantity FROM product_units pu_any
         WHERE pu_any.product_id = p.id
         ORDER BY pu_any.id ASC LIMIT 1),
        0
    )";
    $primaryMinExpr = "COALESCE(
        (SELECT pu_primary.min_stock FROM product_units pu_primary
         WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
         ORDER BY pu_primary.id ASC LIMIT 1),
        (SELECT pu_any.min_stock FROM product_units pu_any
         WHERE pu_any.product_id = p.id
         ORDER BY pu_any.id ASC LIMIT 1),
        0
    )";
    $stockCondition = "";
    switch ($stock_filter) {
        case 'low_stock':
            $stockCondition = " AND $hasPositiveStockExpr AND $primaryStockExpr <= $primaryMinExpr";
            break;
        case 'out_of_stock':
            $stockCondition = " AND NOT $hasPositiveStockExpr";
            break;
        case 'in_stock':
            $stockCondition = " AND $hasPositiveStockExpr";
            break;
    }
}

$whereClause = implode(' AND ', $whereConditions);

// وەرگرتنی هەموو کاڵاکان (currency بۆ نرخەکان بە دۆلار/دینار)
$query = "
    SELECT p.*, p.image_path, p.currency, c.name as category_name,
           pu.id as product_unit_id, pu.unit_id, pu.buy_price as unit_buy_price, 
           pu.sell_price as unit_sell_price, pu.wholesale_price as unit_wholesale_price,
           pu.special_price as unit_special_price, pu.stock_quantity as unit_stock_quantity,
           pu.min_stock as unit_min_stock, pu.currency as unit_currency,
           u.name as unit_name, u.symbol as unit_symbol,
           (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) as unit_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_units pu ON p.id = pu.product_id
    LEFT JOIN units u ON pu.unit_id = u.id
    WHERE $whereClause $stockCondition
    ORDER BY p.created_at DESC, pu.is_primary DESC, pu.id ASC
";

$stmt = $conn->prepare($query);
if (!empty($searchParams)) {
    // اگر کالاهای انتخاب‌شده وجود دارد، همه پارامترها integer هستند
    // در غیر این صورت، همه string هستند
    if (!empty($selectedProductIds)) {
        $types = str_repeat('i', count($searchParams));
    } else {
        $types = str_repeat('s', count($searchParams));
    }
    $stmt->bind_param($types, ...$searchParams);
}

$stmt->execute();
$rawProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// گروپکردنی کاڵاکان بەپێی ID و کۆکردنەوەی یەکەکان
$products = [];
foreach ($rawProducts as $row) {
    $productId = $row['id'];
    
    if (!isset($products[$productId])) {
        $products[$productId] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'image_path' => $row['image_path'],
            'category_name' => $row['category_name'],
            'expiry_date' => $row['expiry_date'],
            'buy_price' => $row['buy_price'] ?? 0,
            'sell_price' => $row['sell_price'] ?? 0,
            'wholesale_price' => $row['wholesale_price'] ?? 0,
            'special_price' => $row['special_price'] ?? 0,
            'stock_quantity' => $row['stock_quantity'] ?? 0,
            'min_stock' => $row['min_stock'] ?? 0,
            'unit_count' => $row['unit_count'],
            'currency' => $row['currency'] ?? 'IQD',
            'units' => []
        ];
    }
    
    if ($row['product_unit_id']) {
        $products[$productId]['units'][] = [
            'unit_name' => $row['unit_name'],
            'unit_symbol' => $row['unit_symbol'],
            'buy_price' => $row['unit_buy_price'],
            'sell_price' => $row['unit_sell_price'],
            'wholesale_price' => $row['unit_wholesale_price'],
            'special_price' => $row['unit_special_price'],
            'stock_quantity' => $row['unit_stock_quantity'],
            'min_stock' => $row['unit_min_stock'],
            'currency' => !empty($row['unit_currency']) ? $row['unit_currency'] : ($row['currency'] ?? 'IQD')
        ];
    }
}

$products = array_values($products);
$totalProducts = count($products);

// حیسابکردنی ژمارەی ڕیزەکان بۆ هەر کاڵا (بەپێی یەکەکان)
function calculateRowsForProduct($product) {
    if (!empty($product['units'])) {
        return count($product['units']);
    }
    return 1;
}

// حیسابکردنی ژمارەی کاڵاکان بەپێی قەبارەی A4
// A4 height: 297mm
// Padding: 20mm top + 20mm bottom = 40mm
// Header: ~60mm (h1 + info + margin)
// Footer: ~30mm
// Table header: ~15mm
// Available space: 297 - 40 - 60 - 30 - 15 = 152mm
// Row height: ~15mm (بەپێی یەکەکان و زانیارییەکان)
// Max rows per page: بەکارهێنەر دەتوانێت دیاری بکات
$maxRowsPerPage = $per_page;

// دابەشکردنی کاڵاکان بە لاپەڕەکان بەپێی ژمارەی ڕیزەکان
$pages = [];
$currentPage = [];
$currentPageRows = 0;

foreach ($products as $product) {
    $productRows = calculateRowsForProduct($product);
    
    // ئەگەر کاڵاکە لە لاپەڕەی ئێستا ناگونجێت، لاپەڕەی نوێ دروست بکە
    if ($currentPageRows + $productRows > $maxRowsPerPage && !empty($currentPage)) {
        $pages[] = $currentPage;
        $currentPage = [];
        $currentPageRows = 0;
    }
    
    $currentPage[] = $product;
    $currentPageRows += $productRows;
}

// لاپەڕەی کۆتایی زیاد بکە
if (!empty($currentPage)) {
    $pages[] = $currentPage;
}

$totalPages = count($pages);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپکردنی کاڵاکان A4 - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #1f2937;
        }

        .print-actions {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }

        .print-actions button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }

        .print-actions .btn-print {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .print-actions .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
        }

        .print-actions .btn-close {
            background: #6b7280;
            color: white;
        }

        .print-actions .btn-close:hover {
            background: #4b5563;
        }

        .print-actions .btn-settings {
            background: #10b981;
            color: white;
        }

        .print-actions .btn-settings:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(16, 185, 129, 0.4);
        }

        .settings-panel {
            position: fixed;
            top: 80px;
            left: 20px;
            z-index: 999;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 350px;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
            display: none;
            transition: all 0.3s ease;
        }

        .settings-panel.show {
            display: block;
        }

        .settings-content {
            padding: 20px;
        }

        .settings-content h5 {
            color: #667eea;
            margin-bottom: 20px;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }

        .setting-group {
            margin-bottom: 20px;
        }

        .setting-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .setting-group input[type="range"] {
            width: 100%;
            margin: 10px 0;
        }

        .range-labels {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #6b7280;
            margin-top: 5px;
        }

        .setting-group input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }

        .setting-group input[type="number"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .radio-label:hover {
            border-color: #667eea;
            background: #f3f4f6;
        }

        .radio-label input[type="radio"] {
            margin: 0;
            cursor: pointer;
        }

        .radio-label input[type="radio"]:checked + span {
            color: #667eea;
            font-weight: 600;
        }

        .radio-label:has(input[type="radio"]:checked) {
            border-color: #667eea;
            background: #eef2ff;
        }

        .settings-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
        }

        .settings-actions button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-apply {
            background: #667eea;
            color: white;
        }

        .btn-apply:hover {
            background: #5568d3;
        }

        .btn-reset {
            background: #6b7280;
            color: white;
        }

        .btn-reset:hover {
            background: #4b5563;
        }

        .a4-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20mm;
            transform-origin: top center;
            transition: transform 0.3s ease;
        }

        .two-table-layout {
            display: flex;
            gap: 2%;
            width: 100%;
        }

        .two-table-layout .products-table {
            width: 49%;
            flex: 1;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }

        .header h1 {
            color: #667eea;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header .info {
            color: #6b7280;
            font-size: 14px;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            border: 3px solid #1f2937;
        }

        .products-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .products-table th {
            padding: 12px 8px;
            text-align: right;
            font-weight: 600;
            font-size: 13px;
            border: 2px solid #1f2937;
        }

        .products-table td {
            padding: 10px 8px;
            border: 2px solid #1f2937;
            font-size: 12px;
        }

        .products-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .products-table tbody tr:hover {
            background-color: #f3f4f6;
        }

        .product-name {
            font-weight: 600;
            color: #1f2937;
        }

        .product-image {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
        }

        .product-image-placeholder {
            width: 40px;
            height: 40px;
            background: #f3f4f6;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            border: 1px solid #e5e7eb;
        }

        .unit-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 4px;
            font-size: 11px;
            margin: 2px;
        }

        .price {
            font-weight: 600;
            color: #059669;
        }

        .stock-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .stock-success {
            background: #d1fae5;
            color: #065f46;
        }

        .stock-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .stock-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .units-section {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #d1d5db;
        }

        .unit-item {
            margin-bottom: 6px;
            padding: 6px;
            background: #f9fafb;
            border-radius: 4px;
        }

        .unit-item:last-child {
            margin-bottom: 0;
        }

        .unit-title {
            font-weight: 600;
            color: #667eea;
            font-size: 11px;
            margin-bottom: 4px;
        }

        .unit-details {
            font-size: 10px;
            color: #6b7280;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }

        .page-break {
            page-break-after: always;
            break-after: page;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                color: #000000 !important;
            }

            .print-actions {
                display: none !important;
            }

            .settings-panel {
                display: none !important;
            }

            .a4-container {
                width: 100%;
                box-shadow: none;
                margin: 0;
                padding: 15mm;
                transform: scale(var(--print-scale, 1));
                transform-origin: top center;
            }

            .products-table tbody tr:hover {
                background-color: transparent;
            }

            .page-break {
                page-break-after: always;
                break-after: page;
            }

            .product-image {
                width: 35px !important;
                height: 35px !important;
            }

            .product-image-placeholder {
                width: 35px !important;
                height: 35px !important;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .a4-container,
            html[data-bs-theme='dark'] .products-table,
            html[data-bs-theme='dark'] .products-table thead,
            html[data-bs-theme='dark'] .products-table tbody tr,
            html[data-bs-theme='dark'] .products-table th,
            html[data-bs-theme='dark'] .products-table td,
            html[data-bs-theme='dark'] .footer {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #1f2937 !important;
                box-shadow: none !important;
            }
        }

        @page {
            size: A4;
            margin: 0;
        }
    </style>
</head>
<body>
    <!-- Print Actions -->
    <div class="print-actions">
        <button class="btn-settings" onclick="toggleSettings()" id="settingsBtn">
            <i class="bi bi-gear"></i> ڕێکخستنەکان
        </button>
        <button class="btn-print" onclick="window.print()">
            <i class="bi bi-printer"></i> چاپکردن
        </button>
        <button class="btn-close" onclick="window.close()">
            <i class="bi bi-x-circle"></i> داخستن
        </button>
    </div>

    <!-- Settings Panel -->
    <div class="settings-panel" id="settingsPanel">
        <div class="settings-content">
            <h5><i class="bi bi-sliders"></i> ڕێکخستنی دیزاینی چاپ</h5>
            
            <div class="setting-group">
                <label for="perPageInput">
                    <i class="bi bi-list-ol"></i> ژمارەی کاڵا لە هەر لاپەڕەیەکدا:
                </label>
                <input type="number" id="perPageInput" min="5" max="30" value="<?php echo $per_page; ?>" class="form-control">
            </div>

            <div class="setting-group">
                <label>
                    <i class="bi bi-table"></i> شێوازی خشتەکان:
                </label>
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="layout" value="single" <?php echo $layout === 'single' ? 'checked' : ''; ?>>
                        <span>یەک خشتە (تەواو پان)</span>
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="layout" value="double" <?php echo $layout === 'double' ? 'checked' : ''; ?>>
                        <span>دوو خشتە (لای چەپ و ڕاست)</span>
                    </label>
                </div>
            </div>

            <div class="settings-actions">
                <button class="btn-apply" onclick="applySettings()">
                    <i class="bi bi-check-circle"></i> جێبەجێکردن
                </button>
                <button class="btn-reset" onclick="resetSettings()">
                    <i class="bi bi-arrow-counterclockwise"></i> گەڕاندنەوە
                </button>
            </div>
        </div>
    </div>

    <?php
    // دابەشکردنی کاڵاکان بە لاپەڕەکان بەپێی قەبارەی A4
    for ($pageNum = 1; $pageNum <= $totalPages; $pageNum++):
        $pageProducts = $pages[$pageNum - 1];
        $isLastPage = ($pageNum == $totalPages);
    ?>
    
    <div class="a4-container <?php echo !$isLastPage ? 'page-break' : ''; ?>" style="transform: scale(<?php echo $scale / 100; ?>); --print-scale: <?php echo $scale / 100; ?>;">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="bi bi-box-seam"></i>
                لیستی کاڵاکان
            </h1>
            <div class="info">
                <div>ناوی فرشگا : <?php echo htmlspecialchars($currentUser['business_name']); ?></div>
                <div>بەروار: <?php echo date('Y/m/d H:i'); ?></div>
                <div>گشتی کاڵاکان: <?php echo number_format($totalProducts); ?> | لاپەڕەی <?php echo $pageNum; ?> لە <?php echo $totalPages; ?></div>
            </div>
        </div>

        <!-- Products Table -->
        <?php
        // دابەشکردنی کاڵاکان بۆ دوو خشتە (ئەگەر layout دوو خشتە بێت)
        if ($layout === 'double') {
            $halfCount = ceil(count($pageProducts) / 2);
            $leftProducts = array_slice($pageProducts, 0, $halfCount);
            $rightProducts = array_slice($pageProducts, $halfCount);
        } else {
            $leftProducts = $pageProducts;
            $rightProducts = [];
        }
        ?>
        
        <div class="<?php echo $layout === 'double' ? 'two-table-layout' : ''; ?>">
            <?php
            // دروستکردنی خشتەی یەکەم (یان تەنها خشتە)
            $tablesToRender = $layout === 'double' ? [$leftProducts, $rightProducts] : [$leftProducts];
            
            foreach ($tablesToRender as $tableIndex => $tableProducts):
                if (empty($tableProducts)) continue;
            ?>
            <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 4%;">#</th>
                    <?php if ($columnsMap['name']): ?>
                        <th style="width: 18%;">ناوی کاڵا</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['image']): ?>
                        <th style="width: 8%;">وێنەی کاڵا</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['category']): ?>
                        <th style="width: 10%;">کەتەلۆگ</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['barcode']): ?>
                        <th style="width: 9%;">بارکۆد</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['unit']): ?>
                        <th style="width: 10%;">یەکە</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['sell_price']): ?>
                        <th style="width: 9%;">نرخی فرۆشتن</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['wholesale_price']): ?>
                        <th style="width: 9%;">نرخی جوملە</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['special_price']): ?>
                        <th style="width: 9%;">نرخی تایبەت</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['stock']): ?>
                        <th style="width: 8%;">بەردەست</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['date']): ?>
                        <th style="width: 7%;">بەروار</th>
                    <?php endif; ?>
                    <?php if ($columnsMap['status']): ?>
                        <th style="width: 7%;">دۆخ</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                // حیسابکردنی ژمارەی ڕیزی دەستپێکردن
                $rowNum = 1;
                for ($i = 0; $i < $pageNum - 1; $i++) {
                    foreach ($pages[$i] as $prevProduct) {
                        $rowNum += calculateRowsForProduct($prevProduct);
                    }
                }
                
                // بۆ دوو خشتە، حیسابکردنی ڕیزی دەستپێکردن بۆ هەر خشتەیەک
                if ($layout === 'double' && $tableIndex === 1) {
                    // خشتەی دووەم: زیادکردنی ڕیزەکانی خشتەی یەکەم
                    foreach ($leftProducts as $leftProduct) {
                        $rowNum += calculateRowsForProduct($leftProduct);
                    }
                }
                
                foreach ($tableProducts as $product):
                    $productRowStart = $rowNum; 
                    // حیسابکردنی دۆخی بەردەست
                    $stockStatus = '';
                    $stockClass = '';
                    $currentStock = 0;
                    $currentMinStock = 0;
                    
                    // کاڵا کاتێک «تەواوبوو»یە کە هیچ یەکەیەکی بڕی بەردەستی > 0 نەبێت
                    $hasAnyStock = false;
                    if (!empty($product['units'])) {
                        $currentStock = $product['units'][0]['stock_quantity'];
                        $currentMinStock = $product['units'][0]['min_stock'];
                        foreach ($product['units'] as $unitRow) {
                            if ((float)$unitRow['stock_quantity'] > 0) {
                                $hasAnyStock = true;
                                break;
                            }
                        }
                    } else {
                        $currentStock = $product['stock_quantity'];
                        $currentMinStock = $product['min_stock'];
                        $hasAnyStock = ((float)$currentStock > 0);
                    }

                    if (!$hasAnyStock) {
                        $stockStatus = 'تەواو بووە';
                        $stockClass = 'stock-danger';
                    } elseif ($currentStock <= $currentMinStock) {
                        $stockStatus = 'کەمە';
                        $stockClass = 'stock-warning';
                    } else {
                        $stockStatus = 'بەردەستە';
                        $stockClass = 'stock-success';
                    }
                    
                    $isExpired = false;
                    if ($product['expiry_date'] && strtotime($product['expiry_date']) <= time()) {
                        $isExpired = true;
                    }
                    
                    // ئەگەر کاڵاکە یەکەکانی هەبێت، بۆ هەر یەکەکە ڕیزی جیاواز دروست بکە
                    if (!empty($product['units'])): 
                        foreach ($product['units'] as $unitIndex => $unit): 
                            $isFirstUnit = ($unitIndex === 0);
                            
                            // حیسابکردنی دۆخی بەردەست بۆ هەر یەکە
                            $unitStockStatus = '';
                            $unitStockClass = '';
                            $unitStock = $unit['stock_quantity'];
                            $unitMinStock = $unit['min_stock'];
                            
                            if ($unitStock == 0) {
                                $unitStockStatus = 'تەواو بووە';
                                $unitStockClass = 'stock-danger';
                            } elseif ($unitStock <= $unitMinStock) {
                                $unitStockStatus = 'کەمە';
                                $unitStockClass = 'stock-warning';
                            } else {
                                $unitStockStatus = 'بەردەستە';
                                $unitStockClass = 'stock-success';
                            }
                ?>
                <tr>
                    <?php if ($isFirstUnit): ?>
                        <td rowspan="<?php echo count($product['units']); ?>" style="vertical-align: top; padding-top: 15px;">
                            <?php echo $productRowStart; ?>
                        </td>
                        <?php if ($columnsMap['name']): ?>
                            <td rowspan="<?php echo count($product['units']); ?>" style="vertical-align: top; padding-top: 15px;">
                                <div class="product-name">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    <?php if ($isExpired): ?>
                                        <span class="badge bg-danger" style="font-size: 9px;">بەسەرچووە</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                        <?php if ($columnsMap['image']): ?>
                            <td rowspan="<?php echo count($product['units']); ?>" style="vertical-align: top; padding-top: 15px; text-align: center;">
                                <?php if ($product['image_path']): ?>
                                    <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                         style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                    <div class="bg-body-secondary rounded d-flex align-items-center justify-content-center" 
                                         style="width: 40px; height: 40px; margin: 0 auto;">
                                        <i class="bi bi-box-seam text-muted" style="font-size: 18px;"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($columnsMap['category']): ?>
                            <td rowspan="<?php echo count($product['units']); ?>" style="vertical-align: top; padding-top: 15px;">
                                <?php echo $product['category_name'] ? htmlspecialchars($product['category_name']) : '-'; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($columnsMap['barcode']): ?>
                            <td rowspan="<?php echo count($product['units']); ?>" style="vertical-align: top; padding-top: 15px;">
                                <?php if ($product['barcode']): ?>
                                    <code style="font-size: 10px;"><?php echo htmlspecialchars($product['barcode']); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($columnsMap['unit']): ?>
                        <td style="border-top: <?php echo $isFirstUnit ? '2px' : '1px'; ?> solid #1f2937;">
                            <div style="font-weight: 600; color: #667eea;">
                                <?php echo htmlspecialchars($unit['unit_name']); ?>
                            </div>
                            <?php if ($unit['unit_symbol']): ?>
                                <div style="font-size: 10px; color: #6b7280;">
                                    (<?php echo htmlspecialchars($unit['unit_symbol']); ?>)
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['sell_price']): ?>
                        <td style="border-top: <?php echo $isFirstUnit ? '2px' : '1px'; ?> solid #1f2937;">
                            <div class="price">
                                <?php echo formatCurrencyAmount($unit['sell_price'], $unit['currency'] ?? $product['currency'] ?? 'IQD'); ?>
                            </div>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['wholesale_price']): ?>
                        <td style="border-top: <?php echo $isFirstUnit ? '2px' : '1px'; ?> solid #1f2937;">
                            <?php if ($unit['wholesale_price'] > 0): ?>
                                <div class="price" style="color: #7c3aed;">
                                    <?php echo formatCurrencyAmount($unit['wholesale_price'], $unit['currency'] ?? $product['currency'] ?? 'IQD'); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['special_price']): ?>
                        <td style="border-top: <?php echo $isFirstUnit ? '2px' : '1px'; ?> solid #1f2937;">
                            <?php if ($unit['special_price'] > 0): ?>
                                <div class="price" style="color: #dc2626;">
                                    <?php echo formatCurrencyAmount($unit['special_price'], $unit['currency'] ?? $product['currency'] ?? 'IQD'); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['stock']): ?>
                        <td style="border-top: <?php echo $isFirstUnit ? '2px' : '1px'; ?> solid #1f2937;">
                            <span class="stock-badge <?php echo $unitStockClass; ?>">
                                <?php echo number_format($unit['stock_quantity']); ?>
                            </span>
                            <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                                کەمترین: <?php echo number_format($unit['min_stock']); ?>
                            </div>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['date'] && $isFirstUnit): ?>
                        <td rowspan="<?php echo count($product['units']); ?>" style="vertical-align: top; padding-top: 15px;">
                            <?php if ($product['expiry_date']): ?>
                                <span style="color: <?php echo $isExpired ? '#dc2626' : '#6b7280'; ?>; font-size: 11px;">
                                    <?php echo date('Y/m/d', strtotime($product['expiry_date'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['status']): ?>
                        <td style="border-top: <?php echo $isFirstUnit ? '2px' : '1px'; ?> solid #1f2937;">
                            <span class="stock-badge <?php echo $unitStockClass; ?>" style="font-size: 10px;">
                                <?php echo $unitStockStatus; ?>
                            </span>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php 
                    endforeach;
                else: 
                    // کاڵای بەبێ یەکە
                ?>
                <tr>
                    <td><?php echo $rowNum; ?></td>
                    <?php if ($columnsMap['name']): ?>
                        <td>
                            <div class="product-name">
                                <?php echo htmlspecialchars($product['name']); ?>
                                <?php if ($isExpired): ?>
                                    <span class="badge bg-danger" style="font-size: 9px;">بەسەرچووە</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['image']): ?>
                        <td style="text-align: center;">
                            <?php if ($product['image_path']): ?>
                                <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div class="bg-body-secondary rounded d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; margin: 0 auto;">
                                    <i class="bi bi-box-seam text-muted" style="font-size: 18px;"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['category']): ?>
                        <td><?php echo $product['category_name'] ? htmlspecialchars($product['category_name']) : '-'; ?></td>
                    <?php endif; ?>
                    <?php if ($columnsMap['barcode']): ?>
                        <td>
                            <?php if ($product['barcode']): ?>
                                <code style="font-size: 10px;"><?php echo htmlspecialchars($product['barcode']); ?></code>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['unit']): ?>
                        <td>
                            <span class="text-muted">-</span>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['sell_price']): ?>
                        <td>
                            <div class="price">
                                <?php echo formatCurrencyAmount($product['sell_price'], $product['currency'] ?? 'IQD'); ?>
                            </div>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['wholesale_price']): ?>
                        <td>
                            <?php if ($product['wholesale_price'] > 0): ?>
                                <div class="price" style="color: #7c3aed;">
                                    <?php echo formatCurrencyAmount($product['wholesale_price'], $product['currency'] ?? 'IQD'); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['special_price']): ?>
                        <td>
                            <?php if ($product['special_price'] > 0): ?>
                                <div class="price" style="color: #dc2626;">
                                    <?php echo formatCurrencyAmount($product['special_price'], $product['currency'] ?? 'IQD'); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['stock']): ?>
                        <td>
                            <span class="stock-badge <?php echo $stockClass; ?>">
                                <?php echo number_format($currentStock); ?>
                            </span>
                            <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                                کەمترین: <?php echo number_format($currentMinStock); ?>
                            </div>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['date']): ?>
                        <td>
                            <?php if ($product['expiry_date']): ?>
                                <span style="color: <?php echo $isExpired ? '#dc2626' : '#6b7280'; ?>; font-size: 11px;">
                                    <?php echo date('Y/m/d', strtotime($product['expiry_date'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <?php if ($columnsMap['status']): ?>
                        <td>
                            <span class="stock-badge <?php echo $stockClass; ?>" style="font-size: 10px;">
                                <?php echo $stockStatus; ?>
                            </span>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endif; ?>
                <?php 
                    // زیادکردنی ژمارەی ڕیزەکان بەپێی کاڵا
                    $rowNum += calculateRowsForProduct($product);
                    endforeach;
                ?>
            </tbody>
            </table>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div>لاپەڕەی <?php echo $pageNum; ?> لە <?php echo $totalPages; ?></div>
            <div style="margin-top: 10px;">
                <i class="bi bi-stars"></i> سیستەمی NexoraCore | NexoraCore.com
            </div>
        </div>
    </div>
    
    <?php endfor; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Load settings from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const savedSettings = localStorage.getItem('printSettings');
            if (savedSettings) {
                try {
                    const settings = JSON.parse(savedSettings);
                    // Apply saved settings to form if URL params don't exist
                    if (!new URLSearchParams(window.location.search).has('per_page')) {
                        document.getElementById('perPageInput').value = settings.per_page || 10;
                    }
                    if (!new URLSearchParams(window.location.search).has('layout')) {
                        const layoutRadio = document.querySelector(`input[name="layout"][value="${settings.layout || 'single'}"]`);
                        if (layoutRadio) layoutRadio.checked = true;
                    }
                } catch (e) {
                    console.error('Error loading settings:', e);
                }
            }
        });

        // Toggle settings panel
        function toggleSettings() {
            const panel = document.getElementById('settingsPanel');
            panel.classList.toggle('show');
        }

        // Apply settings
        function applySettings() {
            const perPage = document.getElementById('perPageInput').value;
            const layout = document.querySelector('input[name="layout"]:checked').value;

            // Validate inputs
            const validPerPage = Math.max(5, Math.min(30, parseInt(perPage)));

            // Save to localStorage
            const settings = {
                per_page: validPerPage,
                layout: layout
            };
            localStorage.setItem('printSettings', JSON.stringify(settings));

            // Build new URL with settings
            const url = new URL(window.location.href);
            url.searchParams.delete('scale'); // Remove scale from URL
            url.searchParams.set('per_page', validPerPage);
            url.searchParams.set('layout', layout);

            // Reload page with new settings
            window.location.href = url.toString();
        }

        // Reset settings to defaults
        function resetSettings() {
            // Clear localStorage
            localStorage.removeItem('printSettings');

            // Reset form to defaults
            document.getElementById('perPageInput').value = 10;
            document.querySelector('input[name="layout"][value="single"]').checked = true;

            // Reload page without settings params
            const url = new URL(window.location.href);
            url.searchParams.delete('scale');
            url.searchParams.delete('per_page');
            url.searchParams.delete('layout');
            window.location.href = url.toString();
        }

        // Close settings panel when clicking outside
        document.addEventListener('click', function(event) {
            const panel = document.getElementById('settingsPanel');
            const btn = document.getElementById('settingsBtn');
            if (panel && panel.classList.contains('show') && 
                !panel.contains(event.target) && 
                !btn.contains(event.target)) {
                panel.classList.remove('show');
            }
        });
    </script>
</body>
</html>

