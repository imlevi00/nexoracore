<?php
/**
 * لیستی کاڵاکان - user/products/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/business_type_helpers.php';
require_once 'includes/custom_fields_helpers.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'products.view', [
    'route' => '/user/products/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];
$hasInventoryValueCards = hasProductsInventoryValueCardsAccess();
$isCurtainShopMode = isCurtainShopMode($conn, (int)$userId);

// وەرگرتنی فیلتەرەکان
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$stock_filter = $_GET['filter'] ?? '';
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$currentQueryString = $_SERVER['QUERY_STRING'] ?? '';
$currentListUrl = url('user/products/index.php' . ($currentQueryString !== '' ? '?' . $currentQueryString : ''));
$limit = $show_all ? 999999 : 20; // کاتێک show_all=1، هەموو کاڵاکان نیشان بدە
$offset = $show_all ? 0 : (($page - 1) * $limit);

// دروستکردنی WHERE clause
$whereConditions = ["p.user_id = $userId"];
$scaleTableCheck = $conn->query("SHOW TABLES LIKE 'scale_products'");
if ($scaleTableCheck && $scaleTableCheck->num_rows > 0) {
    $whereConditions[] = "NOT EXISTS (SELECT 1 FROM scale_products sp_hide WHERE sp_hide.product_id = p.id)";
}
if ($scaleTableCheck) {
    $scaleTableCheck->free();
}
$searchParams = [];

if (!empty($search)) {
    $whereConditions[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
    $searchTerm = "%$search%";
    $searchParams[] = $searchTerm;
    $searchParams[] = $searchTerm;
}

if ($category_filter === '__uncategorized__') {
    // هەندێک کاڵا بە NULL یان 0 بێ کەتەلۆگ تۆمارکراون
    $whereConditions[] = "(p.category_id IS NULL OR p.category_id = 0)";
} elseif (!empty($category_filter) && is_numeric($category_filter)) {
    $whereConditions[] = "p.category_id = ?";
    $searchParams[] = $category_filter;
}
 
// Note: For products with units, we need to check unit stock levels
// This will be applied in the main query with the JOIN
$stockFilterType = $stock_filter; // Store for later use in main query

$whereClause = implode(' AND ', $whereConditions);

// ئامادەکردنی فلتەری stock بۆ بەکارهێنان لە query-ەکاندا
// گرنگ: پێناسەی «تەواوبوو» = هیچ یەکەیەکی ئەم کاڵایە بڕی بەردەستی > 0 نییە.
// پێشتر فلتەری لیست بەپێی «هەر یەکەیەک = 0» هەڵدەسەنگا، بۆیە کاڵایەک کە یەکێک لە یەکەکانی
// سفر بوو (بەڵام یەکەکانی تری پڕ بوون) بە هەڵە وەک تەواوبوو دەردەکەوت. ئەم پێناسە نوێیە
// هەردوو ئاراستەی ناتەبایی یەکەکان دەگرێتەوە و یەکسانە لەگەڵ لۆجیکی فرۆشتنی POS
// (search_product.php: کاڵا کاتێک دەفرۆشرێت کە EXISTS یەکەیەک بڕی بەردەستی > 0 هەبێت).
$hasPositiveStockExpr = "EXISTS (
    SELECT 1 FROM product_units pu_pos
    WHERE pu_pos.product_id = p.id AND pu_pos.stock_quantity > 0
)";
// یەکەی سەرەکی تەنها بۆ نیشاندانی ژمارە و مەرجی «کەم/زەرەر» بەکاردێت
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
$primaryBuyExpr = "COALESCE(
    (SELECT pu_primary.buy_price FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.buy_price FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";
$primarySellExpr = "COALESCE(
    (SELECT pu_primary.sell_price FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.sell_price FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";
$hasUnitsExpr = "(SELECT COUNT(*) FROM product_units WHERE product_id = p.id) > 0";

$stockCondition = "";

switch ($stockFilterType) {
    case 'low_stock':
        // کەم: کاڵا بەردەستی هەیە (یەکەیەک > 0) بەڵام یەکەی سەرەکی <= کەمترین بڕ
        $stockCondition = " AND $hasPositiveStockExpr AND $primaryStockExpr <= $primaryMinExpr";
        break;
    case 'out_of_stock':
        // تەواوبوو: هیچ یەکەیەک بڕی بەردەستی > 0 نییە (کاڵای بێ یەکەش لێرەیە)
        $stockCondition = " AND NOT $hasPositiveStockExpr";
        break;
    case 'in_stock':
        // بەردەست: لانیکەم یەکەیەک بڕی بەردەستی > 0 هەیە
        $stockCondition = " AND $hasPositiveStockExpr";
        break;
    case 'loss':
        // کاڵاکانی بەزەرەر: نرخی کڕینی یەکەی سەرەکی > نرخی فرۆشتنی
        $stockCondition = " AND $hasUnitsExpr AND $primaryBuyExpr > $primarySellExpr";
        break;
}
// هەمان مەرج بۆ query ـی کۆکردنەوە (لەبەر ئەوەی ئێستا هەردووکیان بەپێی یەکەی سەرەکین)
$stockConditionForSum = $stockCondition;

// کۆکردنەوەی گشتی بۆ قیمەتی مەخزەن بە نرخی کڕین و فرۆشتن (جیاکراو بە دراو)
// بۆ کاڵاکانی خاوەن یەکە، تەنها یەکەمی (بچووکترین pu.id) بەکار دەهێنین
$total_buy_value = 0.0;
$total_sell_value = 0.0;
$total_buy_value_iqd = 0.0;
$total_buy_value_usd = 0.0;
$total_sell_value_iqd = 0.0;
$total_sell_value_usd = 0.0;

if ($hasInventoryValueCards) {
$sumQuery = "
    SELECT 
        COALESCE(SUM(
            CASE 
                WHEN (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) = 0 
                THEN 0
                ELSE (
                    SELECT pu_first.stock_quantity * pu_first.buy_price
                    FROM product_units pu_first
                    WHERE pu_first.product_id = p.id
                    ORDER BY pu_first.id ASC
                    LIMIT 1
                )
            END
        ), 0) AS total_buy_value,
        COALESCE(SUM(
            CASE 
                WHEN (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) = 0 
                THEN 0
                ELSE (
                    SELECT pu_first.stock_quantity * pu_first.sell_price
                    FROM product_units pu_first
                    WHERE pu_first.product_id = p.id
                    ORDER BY pu_first.id ASC
                    LIMIT 1
                )
            END
        ), 0) AS total_sell_value,
        COALESCE(SUM(
            CASE 
                WHEN (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) = 0 
                    -- بێ یەکە: گومان دەکەین بە دینارە (IQD)
                    THEN 0
                ELSE (
                    SELECT 
                        CASE 
                            WHEN pu_first.currency = 'USD' THEN 0
                            ELSE pu_first.stock_quantity * pu_first.buy_price
                        END
                    FROM product_units pu_first
                    WHERE pu_first.product_id = p.id
                    ORDER BY pu_first.id ASC
                    LIMIT 1
                )
            END
        ), 0) AS total_buy_value_iqd,
        COALESCE(SUM(
            CASE 
                WHEN (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) = 0 
                    THEN 0
                ELSE (
                    SELECT 
                        CASE 
                            WHEN pu_first.currency = 'USD' THEN pu_first.stock_quantity * pu_first.buy_price
                            ELSE 0
                        END
                    FROM product_units pu_first
                    WHERE pu_first.product_id = p.id
                    ORDER BY pu_first.id ASC
                    LIMIT 1
                )
            END
        ), 0) AS total_buy_value_usd,
        COALESCE(SUM(
            CASE 
                WHEN (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) = 0 
                    THEN 0
                ELSE (
                    SELECT 
                        CASE 
                            WHEN pu_first.currency = 'USD' THEN 0
                            ELSE pu_first.stock_quantity * pu_first.sell_price
                        END
                    FROM product_units pu_first
                    WHERE pu_first.product_id = p.id
                    ORDER BY pu_first.id ASC
                    LIMIT 1
                )
            END
        ), 0) AS total_sell_value_iqd,
        COALESCE(SUM(
            CASE 
                WHEN (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) = 0 
                    THEN 0
                ELSE (
                    SELECT 
                        CASE 
                            WHEN pu_first.currency = 'USD' THEN pu_first.stock_quantity * pu_first.sell_price
                            ELSE 0
                        END
                    FROM product_units pu_first
                    WHERE pu_first.product_id = p.id
                    ORDER BY pu_first.id ASC
                    LIMIT 1
                )
            END
        ), 0) AS total_sell_value_usd
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $whereClause $stockConditionForSum
";

$sumStmt = $conn->prepare($sumQuery);
if (!empty($searchParams)) {
    $types = str_repeat('s', count($searchParams));
    $sumStmt->bind_param($types, ...$searchParams);
}
$sumStmt->execute();
$sums = $sumStmt->get_result()->fetch_assoc();
$total_buy_value = (float)($sums['total_buy_value'] ?? 0);
$total_sell_value = (float)($sums['total_sell_value'] ?? 0);
$total_buy_value_iqd = (float)($sums['total_buy_value_iqd'] ?? 0);
$total_buy_value_usd = (float)($sums['total_buy_value_usd'] ?? 0);
$total_sell_value_iqd = (float)($sums['total_sell_value_iqd'] ?? 0);
$total_sell_value_usd = (float)($sums['total_sell_value_usd'] ?? 0);
}

$query = "
    SELECT p.*, c.name as category_name,
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
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($query);
if (!empty($searchParams)) {
    $types = str_repeat('s', count($searchParams));
    $stmt->bind_param($types, ...$searchParams);
}

$stmt->execute();
$rawProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// گروپکردنی کاڵاکان بەپێی ID و کۆکردنەوەی یەکەکان
$products = [];
$productUnits = [];

foreach ($rawProducts as $row) {
    $productId = $row['id'];
    
    if (!isset($products[$productId])) {
        // یەکەم جار کاڵاکە دەبینین
        $products[$productId] = [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'category_id' => $row['category_id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'expiry_date' => $row['expiry_date'],
            'buy_price' => $row['buy_price'] ?? 0,
            'sell_price' => $row['sell_price'] ?? 0,
            'wholesale_price' => $row['wholesale_price'] ?? 0,
            'special_price' => $row['special_price'] ?? 0,
            'stock_quantity' => $row['stock_quantity'] ?? 0,
            'min_stock' => $row['min_stock'] ?? 0,
            'image_path' => $row['image_path'],
            'fabric_width' => $row['fabric_width'] ?? null,
            'fabric_height' => $row['fabric_height'] ?? null,
            'fabric_measure_unit' => $row['fabric_measure_unit'] ?? 'cm',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'category_name' => $row['category_name'],
            'unit_count' => $row['unit_count'],
            'units' => []
        ];
    }
    
    // زیادکردنی یەکە بۆ کاڵا
    if ($row['product_unit_id']) {
        $products[$productId]['units'][] = [
            'id' => $row['product_unit_id'],
            'unit_id' => $row['unit_id'],
            'unit_name' => $row['unit_name'],
            'unit_symbol' => $row['unit_symbol'],
            'buy_price' => $row['unit_buy_price'],
            'sell_price' => $row['unit_sell_price'],
            'wholesale_price' => $row['unit_wholesale_price'],
            'special_price' => $row['unit_special_price'],
            'stock_quantity' => $row['unit_stock_quantity'],
            'min_stock' => $row['unit_min_stock'],
            'currency' => $row['unit_currency'] ?? 'IQD'
        ];
    }
}

// گۆڕینی بۆ array سادە
$products = array_values($products);

$customValuesMap = getProductCustomFieldValuesMap($conn, $userId, array_column($products, 'id'));
foreach ($products as &$product) {
    $pid = (int)$product['id'];
    $product['custom_fields'] = $customValuesMap[$pid] ?? [];
}
unset($product);

// وەرگرتنی گشتی ژمارە بۆ pagination
// بۆ کاڵاکانی خاوەن یەکە، ژمارەی product-ەکان بژمێرە نەک rowـەکان
$countQuery = "
    SELECT COUNT(DISTINCT p.id) as total 
    FROM products p
    LEFT JOIN product_units pu ON p.id = pu.product_id
    WHERE $whereClause $stockCondition
";
$countStmt = $conn->prepare($countQuery);
if (!empty($searchParams)) {
    $types = str_repeat('s', count($searchParams));
    $countStmt->bind_param($types, ...$searchParams);
}
$countStmt->execute();
$totalProducts = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalProducts / $limit);

// وەرگرتنی کەتەلۆگەکان
$categories = [];
$result = $conn->query("SELECT id, name FROM categories WHERE user_id = $userId ORDER BY name");
if ($result) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selectedProducts = $_POST['selected_products'] ?? [];
    $action = $_POST['bulk_action'];
    $returnUrl = $_POST['return_url'] ?? $currentListUrl;
    $productsIndexBase = url('user/products/index.php');
    if (strpos($returnUrl, $productsIndexBase) !== 0) {
        $returnUrl = $productsIndexBase;
    }
    
    if (!empty($selectedProducts) && Security::validateCSRFToken($_POST['csrf_token'])) {
        $placeholders = implode(',', array_fill(0, count($selectedProducts), '?'));
        
        switch ($action) {
            case 'delete':
                $deleteQuery = "DELETE FROM products WHERE id IN ($placeholders) AND user_id = ?";
                $deleteStmt = $conn->prepare($deleteQuery);
                $params = array_merge($selectedProducts, [$userId]);
                $types = str_repeat('i', count($selectedProducts)) . 'i';
                $deleteStmt->bind_param($types, ...$params);
                
                if ($deleteStmt->execute()) {
                    setMessage('کاڵا هەڵبژێردراوەکان سڕانەوە', 'success');
                } else {
                    setMessage('هەڵە لە سڕینەوەی کاڵاکان', 'error');
                }
                break;
                
            case 'update_category':
                $newCategoryId = $_POST['new_category_id'] ?? null;
                if ($newCategoryId) {
                    $updateQuery = "UPDATE products SET category_id = ? WHERE id IN ($placeholders) AND user_id = ?";
                    $updateStmt = $conn->prepare($updateQuery);
                    $params = array_merge([$newCategoryId], $selectedProducts, [$userId]);
                    $types = 'i' . str_repeat('i', count($selectedProducts)) . 'i';
                    $updateStmt->bind_param($types, ...$params);
                    
                    if ($updateStmt->execute()) {
                        setMessage('کەتەلۆگی کاڵاکان نوێکرایەوە', 'success');
                    }
                }
                break;
                
            case 'reset_available_stock':
                // Reset available stock to 0 for unit-based inventory.
                // (The project stores stock quantities in `product_units`, not in `products`.)
                $conn->begin_transaction();
                
                $unitsUpdateQuery = "UPDATE product_units pu
                    JOIN products p ON pu.product_id = p.id
                    SET pu.stock_quantity = 0
                    WHERE p.id IN ($placeholders) AND p.user_id = ?";
                $unitsUpdateStmt = $conn->prepare($unitsUpdateQuery);
                
                if (!$unitsUpdateStmt) {
                    $conn->rollback();
                    setMessage('هەڵە لە ئامادەکردنی داواکاری bulk بۆ بڕی بەردەست', 'error');
                    break;
                }
                
                $params = array_merge($selectedProducts, [$userId]);
                $types = str_repeat('i', count($selectedProducts)) . 'i';
                
                $unitsUpdateStmt->bind_param($types, ...$params);
                $unitsOk = $unitsUpdateStmt->execute();
                
                if ($unitsOk) {
                    $conn->commit();
                    setMessage('بڕی بەردەست بۆ کاڵاکانی یەکەدار بە 0 کرایەوە', 'success');
                } else {
                    $conn->rollback();
                    setMessage('هەڵە لە گۆڕینی بڕی بەردەست', 'error');
                }
                break;
        }
        
        redirect($returnUrl);
    }
}

$csrf_token = Security::generateCSRFToken();

$stockFilterLabels = [
    'in_stock' => 'لە بەردەستدا',
    'low_stock' => 'کاڵا کەمەکان',
    'out_of_stock' => 'تەواو بووەکان',
    'loss' => 'کاڵا بەزەرەر',
];
$stockFilterLabel = $stockFilterLabels[$stock_filter] ?? 'هەموو کاڵاکان';
$isLossFilter = ($stock_filter === 'loss');
$filtersActive = $search !== '' || $category_filter !== '' || $stock_filter !== '';
$filterBaseQs = 'search=' . urlencode($search) . '&category=' . urlencode((string) $category_filter);
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>کاڵاکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-list.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <?php renderPremiumLockStylesheetLink(); ?>
    <style>
        /* Reduce spacing between checkbox and label in print columns modal */
        #printColumnsModal .form-check {
            display: flex !important;
            align-items: center !important;
            gap: 0.5em !important; /* Small gap between checkbox and label */
            padding-right: 0 !important; /* Remove any default padding that might push it */
        }
        #printColumnsModal .form-check-input {
            margin-right: 0 !important;
            margin-left: 0 !important;
            margin-top: 0 !important; /* Remove any default vertical margin */
            flex-shrink: 0; /* Prevent checkbox from shrinking */
        }
        #printColumnsModal .form-check-label {
            padding-right: 0 !important;
            margin-right: 0 !important;
        }

        .product-search-clear-btn {
            display: none;
            align-items: center;
            justify-content: center;
            padding-inline: 0.65rem;
        }

        .product-search-clear-btn.show {
            display: inline-flex;
        }

        .product-search-input-group.is-searching .input-group-text i.bi-search {
            display: none;
        }

        .product-search-input-group.is-searching .input-group-text::after {
            content: '';
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: product-search-spin 0.6s linear infinite;
            opacity: 0.65;
        }

        @keyframes product-search-spin {
            to { transform: rotate(360deg); }
        }

        /* Floating Panel for Selected Products */
        #selectedProductsPanel {
            position: fixed;
            top: 80px;
            left: 20px;
            width: 380px;
            max-height: calc(100vh - 100px);
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            z-index: 1050;
            display: none;
            flex-direction: column;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(91, 115, 232, 0.1);
        }

        #selectedProductsPanel.show {
            display: flex;
            animation: slideInLeft 0.3s ease-out;
        }

        #selectedProductsPanel.hide {
            animation: slideOutLeft 0.3s ease-in;
        }

        @keyframes slideInLeft {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutLeft {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(-100%);
                opacity: 0;
            }
        }

        #selectedProductsPanel .panel-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            cursor: move;
            user-select: none;
        }

        #selectedProductsPanel .panel-header.dragging {
            opacity: 0.9;
        }

        #selectedProductsPanel .panel-header h5 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #selectedProductsPanel .panel-header .badge {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
        }

        #selectedProductsPanel .panel-header .btn-close-panel {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        #selectedProductsPanel .panel-header .btn-close-panel:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        #selectedProductsPanel .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        #selectedProductsPanel .panel-body::-webkit-scrollbar {
            width: 6px;
        }

        #selectedProductsPanel .panel-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        #selectedProductsPanel .panel-body::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }

        #selectedProductsPanel .panel-body::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        #selectedProductsPanel .selected-product-item {
            display: flex;
            gap: 12px;
            padding: 12px;
            margin-bottom: 12px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
            position: relative;
        }

        #selectedProductsPanel .selected-product-item:hover {
            background: #e9ecef;
            transform: translateX(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        #selectedProductsPanel .selected-product-item .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            flex-shrink: 0;
            border: 2px solid #dee2e6;
        }

        #selectedProductsPanel .selected-product-item .product-image-placeholder {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            flex-shrink: 0;
        }

        #selectedProductsPanel .selected-product-item .product-info {
            flex: 1;
            min-width: 0;
        }

        #selectedProductsPanel .selected-product-item .product-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #212529;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #selectedProductsPanel .selected-product-item .product-price {
            font-size: 0.85rem;
            color: #198754;
            font-weight: 600;
            margin-bottom: 2px;
        }

        #selectedProductsPanel .selected-product-item .product-stock {
            font-size: 0.75rem;
            color: #6c757d;
        }

        #selectedProductsPanel .selected-product-item .btn-remove {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(220, 53, 69, 0.1);
            border: none;
            color: #dc3545;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 12px;
        }

        #selectedProductsPanel .selected-product-item .btn-remove:hover {
            background: #dc3545;
            color: white;
            transform: scale(1.1);
        }

        #selectedProductsPanel .panel-empty {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        #selectedProductsPanel .panel-empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        #selectedProductsPanel .panel-empty p {
            margin: 0;
            font-size: 0.9rem;
        }

        /* Toggle Button */
        #toggleSelectedPanel {
            position: fixed;
            top: 80px;
            left: 20px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 50%;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            z-index: 1049;
            transition: all 0.3s;
        }

        #toggleSelectedPanel:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.6);
        }

        #toggleSelectedPanel .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            min-width: 22px;
            height: 22px;
            padding: 0 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }

        #toggleSelectedPanel .badge.is-empty {
            background: #6c757d;
        }

        /* Responsive */
        @media (max-width: 768px) {
            #selectedProductsPanel {
                width: calc(100vw - 40px);
                left: 20px;
                right: 20px;
            }
        }

        .inline-editable {
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.15s ease, box-shadow 0.15s ease;
        }

        .inline-editable:hover:not(.is-editing):not(.is-saving) {
            background-color: rgba(13, 110, 253, 0.08);
            box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.2);
        }

        .inline-editable.is-editing {
            cursor: text;
            background: transparent;
            box-shadow: none;
        }

        .inline-editable.is-saving {
            opacity: 0.6;
            pointer-events: none;
        }

        .inline-editable .inline-editing-input {
            min-width: 80px;
            max-width: 140px;
        }

        .barcode-editable code {
            cursor: pointer;
        }

        .product-name-editable.inline-editable .inline-editing-input {
            min-width: 120px;
            max-width: 220px;
        }

        .product-image-upload-trigger {
            position: relative;
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            cursor: pointer;
            border-radius: 0.375rem;
            overflow: hidden;
        }

        .product-image-upload-trigger img,
        .product-image-upload-trigger .product-image-placeholder {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        .product-image-upload-trigger .product-image-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .product-image-upload-trigger .product-image-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.45);
            color: #fff;
            opacity: 0;
            transition: opacity 0.15s ease;
            border-radius: 0.375rem;
            pointer-events: none;
        }

        .product-image-upload-trigger:hover:not(.is-uploading) .product-image-overlay {
            opacity: 1;
        }

        .product-image-upload-trigger.is-uploading {
            opacity: 0.6;
            pointer-events: none;
        }

        .product-image-upload-trigger.is-uploading .product-image-overlay {
            opacity: 1;
        }
    </style>
</head>
<body class="products-module-page products-list-page<?php echo $isLossFilter ? ' is-loss-filter' : ''; ?>">

    <?php
    $productsNavId = 'productsListNav';
    $productsNavLinks = [
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <!-- Main Content -->
    <div class="container-fluid py-4 products-page-content pl-wrap">
        
        <!-- Flash Messages -->
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

        <header class="pl-hero">
            <div class="pl-hero-main">
                <?php if ($isLossFilter): ?>
                    <div class="pl-kicker"><i class="bi bi-exclamation-triangle"></i> کاڵا بەزەرەر</div>
                    <h1><i class="bi bi-graph-down-arrow"></i> کاڵا بەزەرەر تۆمارکراوەکان</h1>
                    <p class="pl-hero-sub">کاڵایەکان کە نرخی کڕینیان لە نرخی فرۆشتن زیاترە — پێویستیان بە پێداچوونەوەی نرخ هەیە</p>
                <?php else: ?>
                    <div class="pl-kicker"><i class="bi bi-box-seam"></i> بەشی کاڵاکان</div>
                    <h1><i class="bi bi-boxes"></i> بەڕێوەبردنی کاڵاکان</h1>
                    <p class="pl-hero-sub">لیست، گەڕان، فلتەر و دەستکاریکردنی کاڵاکان لە یەک شوێن</p>
                <?php endif; ?>
                <div class="pl-hero-pills">
                    <span class="pl-pill"><i class="bi bi-collection"></i> <?php echo number_format($totalProducts); ?> کاڵا</span>
                    <span class="pl-pill"><i class="bi bi-funnel"></i> <?php echo htmlspecialchars($stockFilterLabel); ?></span>
                    <?php if ($show_all): ?>
                        <span class="pl-pill"><i class="bi bi-list-ul"></i> هەموو لیستەکە</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pl-hero-actions">
                <a href="<?php echo url('user/products/add.php?return_url=' . urlencode($currentListUrl)); ?>" class="pl-btn pl-btn-primary">
                    <i class="bi bi-plus-lg"></i> کاڵای نوێ
                </a>
                <button type="button" class="pl-btn pl-btn-ghost" data-bs-toggle="modal" data-bs-target="#printColumnsModal">
                    <i class="bi bi-printer"></i> چاپ A4
                </button>
                <a href="<?php echo url('user/products/categories.php'); ?>" class="pl-btn pl-btn-ghost">
                    <i class="bi bi-tags"></i> کەتەلۆگەکان
                </a>
                <a href="<?php echo url('user/products/expired.php'); ?>" class="pl-btn pl-btn-warn">
                    <i class="bi bi-calendar-x"></i> بەسەرچووەکان
                </a>
                <?php if ($totalProducts > 20 && !$show_all): ?>
                    <a href="?page=1&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&filter=<?php echo $stock_filter; ?>&show_all=1"
                       class="pl-btn pl-btn-ghost">
                        <i class="bi bi-list-ul"></i> نیشاندانی هەموو
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($isLossFilter): ?>
            <div class="pl-loss-banner">
                <i class="bi bi-exclamation-octagon"></i>
                <div>
                    <strong>ئەم لیستە تەنها کاڵای بەزەرەر نیشان دەدات</strong>
                    <p>کاتێک نرخی کڕین لە نرخی فرۆشتن زیاتر بێت، قازانج نەگەتیڤ دەبێت. نرخەکان بگۆڕە یان داشکاندن لابەرە.</p>
                </div>
            </div>
        <?php endif; ?>

		<!-- Inventory Summary Cards -->
		<div class="premium-lock-section mb-4">
			<div class="pl-stats <?php echo premiumLockContentClass($hasInventoryValueCards); ?>">
				<div class="pl-stat pl-stat-buy">
					<div class="pl-stat-icon"><i class="bi bi-bag-check"></i></div>
					<div class="pl-stat-body">
						<div class="pl-stat-label">مەخزەن بە نرخی کڕین</div>
						<?php if ($hasInventoryValueCards): ?>
						<div class="pl-stat-row">
							<span>IQD</span>
							<b><?php echo formatMoney($total_buy_value_iqd, 'IQD'); ?></b>
						</div>
						<div class="pl-stat-row">
							<span>USD</span>
							<b><?php echo formatMoney($total_buy_value_usd, 'USD'); ?></b>
						</div>
						<?php else: ?>
						<div class="pl-stat-row" aria-hidden="true">
							<span>IQD</span>
							<b>—</b>
						</div>
						<div class="pl-stat-row" aria-hidden="true">
							<span>USD</span>
							<b>—</b>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<div class="pl-stat pl-stat-sell">
					<div class="pl-stat-icon"><i class="bi bi-cash-coin"></i></div>
					<div class="pl-stat-body">
						<div class="pl-stat-label">مەخزەن بە نرخی فرۆشتن</div>
						<?php if ($hasInventoryValueCards): ?>
						<div class="pl-stat-row">
							<span>IQD</span>
							<b><?php echo formatMoney($total_sell_value_iqd, 'IQD'); ?></b>
						</div>
						<div class="pl-stat-row">
							<span>USD</span>
							<b><?php echo formatMoney($total_sell_value_usd, 'USD'); ?></b>
						</div>
						<?php else: ?>
						<div class="pl-stat-row" aria-hidden="true">
							<span>IQD</span>
							<b>—</b>
						</div>
						<div class="pl-stat-row" aria-hidden="true">
							<span>USD</span>
							<b>—</b>
						</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php if (!$hasInventoryValueCards): ?>
				<?php renderPremiumLockSectionOverlay(
					getPackageFeatureUsageSuffix('products_inventory_value_cards', 'بینینی کارتی مەخزەن بە نرخی کڕین و فرۆشتن'),
					['compact' => true]
				); ?>
			<?php endif; ?>
		</div>

        <!-- Filters -->
        <div class="card pl-filter-card">
            <div class="card-body">
                <div class="pl-filter-head">
                    <h2 class="pl-filter-title"><i class="bi bi-funnel"></i> فلتەر و گەڕان</h2>
                    <div class="pl-chips">
                        <a class="pl-chip<?php echo $stock_filter === '' ? ' is-active' : ''; ?>" href="?<?php echo $filterBaseQs; ?>">هەموو</a>
                        <a class="pl-chip<?php echo $stock_filter === 'in_stock' ? ' is-active' : ''; ?>" href="?<?php echo $filterBaseQs; ?>&filter=in_stock">لە بەردەستدا</a>
                        <a class="pl-chip<?php echo $stock_filter === 'low_stock' ? ' is-active' : ''; ?>" href="?<?php echo $filterBaseQs; ?>&filter=low_stock">کەمەکان</a>
                        <a class="pl-chip<?php echo $stock_filter === 'out_of_stock' ? ' is-active' : ''; ?>" href="?<?php echo $filterBaseQs; ?>&filter=out_of_stock">تەواوبوو</a>
                        <a class="pl-chip is-loss<?php echo $stock_filter === 'loss' ? ' is-active' : ''; ?>" href="?<?php echo $filterBaseQs; ?>&filter=loss">بەزەرەر</a>
                        <?php if ($filtersActive): ?>
                            <a class="pl-chip pl-chip-reset" href="<?php echo url('user/products/index.php'); ?>">
                                <i class="bi bi-x-lg"></i> پاککردنەوە
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">گەڕان لە کاڵاکان</label>
                        <div class="input-group product-search-input-group">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control" name="search" id="productSearchInput"
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="ناوی کاڵا یان بارکۆد... (خۆکار)"
                                   autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                            <button type="button"
                                    class="btn btn-outline-secondary product-search-clear-btn<?php echo !empty($search) ? ' show' : ''; ?>"
                                    id="clearProductSearch"
                                    aria-label="سڕینەوەی گەڕان"
                                    title="سڕینەوەی گەڕان">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">کەتەلۆگ</label>
                        <select class="form-select" name="category">
                            <option value="">هەموو کەتەلۆگەکان</option>
                            <option value="__uncategorized__" <?php echo ($category_filter === '__uncategorized__') ? 'selected' : ''; ?>>
                                بێ کەتەلۆگ
                            </option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                        <?php echo ($category_filter == $category['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">فیلتەری بەردەست</label>
                        <select class="form-select" name="filter">
                            <option value="">هەموو کاڵاکان</option>
                            <option value="in_stock" <?php echo ($stock_filter === 'in_stock') ? 'selected' : ''; ?>>
                                لە بەردەستدا
                            </option>
                            <option value="low_stock" <?php echo ($stock_filter === 'low_stock') ? 'selected' : ''; ?>>
                                کاڵا کەمەکان
                            </option>
                            <option value="out_of_stock" <?php echo ($stock_filter === 'out_of_stock') ? 'selected' : ''; ?>>
                                تەواو بووەکان
                            </option>
                            <option value="loss" <?php echo ($stock_filter === 'loss') ? 'selected' : ''; ?>>
                                کاڵا بەزەرەر تۆمارکراوەکان
                            </option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> گەڕان
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Actions Form -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($currentListUrl); ?>">
            
            <!-- Bulk Actions Bar -->
            <div class="card mb-3 pl-bulk-bar" id="bulkActionsBar">
                <div class="card-body p-3">
                    <div class="row align-items-center g-2">
                        <div class="col-auto">
                            <span class="text-muted">
                                <span id="selectedCount" class="pl-bulk-count">0</span> کاڵا هەڵبژێردراوە
                            </span>
                        </div>
                        <div class="col-auto">
                            <select class="form-select form-select-sm" name="bulk_action" required>
                                <option value="">کرداری گشتی هەڵبژێرە...</option>
                                <option value="delete">سڕینەوە</option>
                                <option value="reset_available_stock">سفر کردنەوەی بڕی بەردەست</option>
                                <option value="update_category">گۆڕینی کەتەلۆگ</option>
                            </select>
                        </div>
                        <div class="col-auto" id="categorySelector" style="display: none;">
                            <select class="form-select form-select-sm" name="new_category_id">
                                <option value="">کەتەلۆگی نوێ هەڵبژێرە...</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check"></i> جێبەجێکردن
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" id="clearSelection">
                                <i class="bi bi-x"></i> پاککردنەوە
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Products Table -->
            <div class="card pl-table-card">
                <div class="pl-table-head">
                    <h2><i class="bi bi-list-ul"></i> لیستی کاڵاکان</h2>
                    <span class="pl-count-badge"><?php echo number_format($totalProducts); ?> کاڵا</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($products)): ?>
                        <div class="pl-empty">
                            <div class="pl-empty-icon"><i class="bi bi-box-seam"></i></div>
                            <h3>هیچ کاڵایەک نەدۆزرایەوە</h3>
                            <p>
                                <?php if (!empty($search) || !empty($category_filter) || !empty($stock_filter)): ?>
                                    فیلتەرەکان بگۆڕە یان
                                    <a href="<?php echo url('user/products/index.php'); ?>">هەموو کاڵاکان ببینە</a>
                                <?php else: ?>
                                    یەکەم کاڵاکەت زیاد بکە
                                <?php endif; ?>
                            </p>
                            <a href="<?php echo url('user/products/add.php?return_url=' . urlencode($currentListUrl)); ?>" class="pl-btn pl-btn-primary">
                                <i class="bi bi-plus-lg"></i> زیادکردنی کاڵای نوێ
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 products-list-table">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                            </div>
                                        </th>
                                        <th>کاڵا</th>
                                        <?php if (!empty($isCurtainShopMode)): ?>
                                        <th>پانی</th>
                                        <th>بەرزی</th>
                                        <?php endif; ?>
                                        <th>کەتەلۆگ</th>
                                        <th>بارکۆد</th>
                                        <th>جۆری یەکە</th>
                                        <th>نرخی کڕین</th>
                                        <th>نرخ</th>
                                        <th>بەردەست</th>
                                        <th>بەروار</th>
                                        <th>دۆخ</th>
                                        <th width="155">کردارەکان</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        // Calculate stock status based on whether product has units or not
                                        $stockStatus = '';
                                        $stockClass = '';
                                        $currentStock = 0;
                                        $currentMinStock = 0;
                                        
                                        // کاڵا کاتێک «تەواوبوو»یە کە هیچ یەکەیەکی بڕی بەردەستی > 0 نەبێت
                                        $hasAnyStock = false;
                                        if (!empty($product['units'])) {
                                            // یەکەکان بەپێی is_primary DESC ڕیزکراون، بۆیە units[0] = یەکەی سەرەکی
                                            $currentStock = $product['units'][0]['stock_quantity'];
                                            $currentMinStock = $product['units'][0]['min_stock'];
                                            foreach ($product['units'] as $unitRow) {
                                                if ((float)$unitRow['stock_quantity'] > 0) {
                                                    $hasAnyStock = true;
                                                    break;
                                                }
                                            }
                                        } else {
                                            // For products without units, use main product stock
                                            $currentStock = $product['stock_quantity'];
                                            $currentMinStock = $product['min_stock'];
                                            $hasAnyStock = ((float)$currentStock > 0);
                                        }

                                        if (!$hasAnyStock) {
                                            $stockStatus = 'تەواو بووە';
                                            $stockClass = 'danger';
                                        } elseif ($currentStock <= $currentMinStock) {
                                            $stockStatus = 'کەمە';
                                            $stockClass = 'warning';
                                        } else {
                                            $stockStatus = 'بەردەستە';
                                            $stockClass = 'success';
                                        }
                                        
                                        // Check if product is a loss product (buy_price > sell_price)
                                        $isLossProduct = false;
                                        if (!empty($product['units'])) {
                                            $isLossProduct = $product['units'][0]['buy_price'] > $product['units'][0]['sell_price'];
                                        } else {
                                            $isLossProduct = $product['buy_price'] > $product['sell_price'];
                                        }
                                        
                                        // Change color from green to yellow for loss products
                                        if ($isLossProduct && $stockClass === 'success') {
                                            $stockClass = 'warning';
                                        }
                                        
                                        $isExpired = false;
                                        if ($product['expiry_date'] && strtotime($product['expiry_date']) <= time()) {
                                            $isExpired = true;
                                        }

                                        $hasUnits = !empty($product['units']);
                                        $firstUnit = $hasUnits ? $product['units'][0] : null;
                                        $currentProductUnitId = $firstUnit ? (int)$firstUnit['id'] : 0;
                                        $buyPriceRaw = $hasUnits ? (float)$firstUnit['buy_price'] : (float)$product['buy_price'];
                                        $sellPriceRaw = $hasUnits ? (float)$firstUnit['sell_price'] : (float)$product['sell_price'];
                                        $unitCurrency = $hasUnits ? ($firstUnit['currency'] ?? 'IQD') : 'IQD';

                                        $fabricWidthText = '';
                                        $fabricHeightText = '';
                                        if (!empty($isCurtainShopMode)) {
                                            $fabricUnitLabel = (strtolower((string)($product['fabric_measure_unit'] ?? 'cm')) === 'm') ? 'م' : 'سم';
                                            $fabricWidthNumber = formatCurtainFabricSizeNumber($product['fabric_width'] ?? null);
                                            $fabricHeightNumber = formatCurtainFabricSizeNumber($product['fabric_height'] ?? null);
                                            $fabricWidthText = $fabricWidthNumber !== '' ? ($fabricWidthNumber . ' ' . $fabricUnitLabel) : '-';
                                            $fabricHeightText = $fabricHeightNumber !== '' ? ($fabricHeightNumber . ' ' . $fabricUnitLabel) : '-';
                                        }
                                        ?>
                                        <tr class="<?php echo trim(($isExpired ? 'table-danger ' : '') . ($isLossProduct ? 'pl-row-loss' : '')); ?>">
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input product-checkbox" 
                                                           type="checkbox"
                                                           value="<?php echo $product['id']; ?>"
                                                           data-product-id="<?php echo $product['id']; ?>"
                                                           data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                                           data-product-image="<?php echo $product['image_path'] ? htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                           data-product-price="<?php echo !empty($product['units']) ? $product['units'][0]['sell_price'] : $product['sell_price']; ?>"
                                                           data-product-stock="<?php echo $currentStock; ?>"
                                                           data-product-currency="<?php echo !empty($product['units']) ? ($product['units'][0]['currency'] ?? 'IQD') : 'IQD'; ?>">
                                                </div>
                                            </td>
                                            <td data-label="کاڵا">
                                                <div class="d-flex align-items-center">
                                                    <div class="product-image-upload-trigger me-2"
                                                         data-product-id="<?php echo $product['id']; ?>"
                                                         title="کلیک بکە بۆ گۆڕینی وێنە">
                                                        <?php if ($product['image_path']): ?>
                                                            <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                                 alt="Product Image"
                                                                 class="rounded product-image-thumb">
                                                        <?php else: ?>
                                                            <div class="bg-body-secondary rounded product-image-placeholder">
                                                                <i class="bi bi-box-seam text-muted"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <span class="product-image-overlay">
                                                            <i class="bi bi-camera-fill"></i>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <strong class="inline-editable product-name-editable"
                                                                data-field="name"
                                                                data-product-id="<?php echo $product['id']; ?>"
                                                                data-raw-value="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                                                title="کلیک بکە بۆ دەستکاری"><?php echo htmlspecialchars($product['name']); ?></strong>
                                                        <?php if ($isLossProduct): ?>
                                                            <br><span class="pl-loss-badge"><i class="bi bi-graph-down-arrow"></i> بەزەرەر</span>
                                                        <?php endif; ?>
                                                        <?php if ($isExpired): ?>
                                                            <br><small class="text-danger">
                                                                <i class="bi bi-calendar-x"></i> بەسەرچووە
                                                            </small>
                                                        <?php elseif ($product['expiry_date'] && strtotime($product['expiry_date']) <= strtotime('+30 days')): ?>
                                                            <br><small class="text-warning">
                                                                <i class="bi bi-clock"></i> نزیکە بەسەرچێت
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <?php if (!empty($isCurtainShopMode)): ?>
                                            <td data-label="پانی"><?php echo htmlspecialchars($fabricWidthText); ?></td>
                                            <td data-label="بەرزی"><?php echo htmlspecialchars($fabricHeightText); ?></td>
                                            <?php endif; ?>
                                            <td data-label="کەتەلۆگ">
                                                <?php if ($product['category_name']): ?>
                                                    <span class="pl-category"><?php echo htmlspecialchars($product['category_name']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="بارکۆد" class="pl-barcode">
                                                <span class="inline-editable barcode-editable"
                                                      data-field="barcode"
                                                      data-product-id="<?php echo $product['id']; ?>"
                                                      data-raw-value="<?php echo htmlspecialchars($product['barcode'] ?? '', ENT_QUOTES); ?>"
                                                      title="کلیک بکە بۆ دەستکاری">
                                                    <?php if ($product['barcode']): ?>
                                                        <code><?php echo htmlspecialchars($product['barcode']); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td data-label="جۆری یەکە">
                                                <?php if (!empty($product['units'])): ?>
                                                    <?php if (count($product['units']) == 1): ?>
                                                        <!-- کاڵایەک یەک یەکەی هەیە -->
                                                        <span class="badge bg-primary">
                                                            <?php echo htmlspecialchars($product['units'][0]['unit_name']); ?>
                                                            <?php if ($product['units'][0]['unit_symbol']): ?>
                                                                (<?php echo htmlspecialchars($product['units'][0]['unit_symbol']); ?>)
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <!-- کاڵایەک چەند یەکەی هەیە -->
                                                        <div class="unit-selector">
                                                            <select class="form-select form-select-sm unit-dropdown" 
                                                                    data-product-id="<?php echo $product['id']; ?>"
                                                                    onchange="switchUnit(<?php echo $product['id']; ?>, this.value)">
                                                                <?php foreach ($product['units'] as $index => $unit): ?>
                                                                    <option value="<?php echo $index; ?>" 
                                                                            data-product-unit-id="<?php echo (int)$unit['id']; ?>"
                                                                            data-buy-price="<?php echo $unit['buy_price']; ?>"
                                                                            data-sell-price="<?php echo $unit['sell_price']; ?>"
                                                                            data-wholesale-price="<?php echo $unit['wholesale_price']; ?>"
                                                                            data-special-price="<?php echo $unit['special_price']; ?>"
                                                                            data-stock="<?php echo $unit['stock_quantity']; ?>"
                                                                            data-min-stock="<?php echo $unit['min_stock']; ?>"
                                                                            data-currency="<?php echo htmlspecialchars($unit['currency'] ?? 'IQD'); ?>"
                                                                            <?php echo $index == 0 ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($unit['unit_name']); ?>
                                                                        <?php if ($unit['unit_symbol']): ?>
                                                                            (<?php echo htmlspecialchars($unit['unit_symbol']); ?>)
                                                                        <?php endif; ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="نرخی کڕین">
                                                <div class="buy-price-display" data-product-id="<?php echo $product['id']; ?>">
                                                    <strong class="text-primary buy-price inline-editable"
                                                            data-field="buy_price"
                                                            data-product-id="<?php echo $product['id']; ?>"
                                                            data-product-unit-id="<?php echo $currentProductUnitId; ?>"
                                                            data-has-units="<?php echo $hasUnits ? '1' : '0'; ?>"
                                                            data-raw-value="<?php echo $buyPriceRaw; ?>"
                                                            data-currency="<?php echo htmlspecialchars($unitCurrency, ENT_QUOTES); ?>"
                                                            title="کلیک بکە بۆ دەستکاری">
                                                        <?php echo formatMoney($buyPriceRaw, $unitCurrency); ?>
                                                    </strong>
                                                </div>
                                            </td>
                                            <td data-label="نرخ">
                                                <div class="price-display" data-product-id="<?php echo $product['id']; ?>">
                                                    <strong class="text-<?php echo $isLossProduct ? 'warning' : 'success'; ?> sell-price inline-editable"
                                                            data-field="sell_price"
                                                            data-product-id="<?php echo $product['id']; ?>"
                                                            data-product-unit-id="<?php echo $currentProductUnitId; ?>"
                                                            data-has-units="<?php echo $hasUnits ? '1' : '0'; ?>"
                                                            data-raw-value="<?php echo $sellPriceRaw; ?>"
                                                            data-currency="<?php echo htmlspecialchars($unitCurrency, ENT_QUOTES); ?>"
                                                            title="کلیک بکە بۆ دەستکاری">
                                                        <?php echo formatMoney($sellPriceRaw, $unitCurrency); ?>
                                                    </strong>
                                                </div>
                                                <div class="additional-prices" data-product-id="<?php echo $product['id']; ?>" style="display: none;">
                                                    <small class="text-muted">
                                                        <span class="wholesale-price">
                                                            <?php 
                                                            if (!empty($product['units'])) {
                                                                $unitCurrency = $product['units'][0]['currency'] ?? 'IQD';
                                                                if ($product['units'][0]['wholesale_price'] > 0) {
                                                                    echo 'جوملە: ' . formatMoney($product['units'][0]['wholesale_price'], $unitCurrency);
                                                                }
                                                            } else {
                                                                if ($product['wholesale_price'] > 0) {
                                                                    echo 'جوملە: ' . formatMoney($product['wholesale_price']);
                                                                }
                                                            }
                                                            ?>
                                                        </span>
                                                        <span class="special-price">
                                                            <?php 
                                                            if (!empty($product['units'])) {
                                                                $unitCurrency = $product['units'][0]['currency'] ?? 'IQD';
                                                                if ($product['units'][0]['special_price'] > 0) {
                                                                    echo '| تایبەت: ' . formatMoney($product['units'][0]['special_price'], $unitCurrency);
                                                                }
                                                            } else {
                                                                if ($product['special_price'] > 0) {
                                                                    echo '| تایبەت: ' . formatMoney($product['special_price']);
                                                                }
                                                            }
                                                            ?>
                                                        </span>
                                                    </small>
                                                </div>
                                            </td>
                                            <td data-label="بەردەست">
                                                <div class="stock-display" data-product-id="<?php echo $product['id']; ?>"
                                                     data-min-stock="<?php echo $currentMinStock; ?>">
                                                    <span class="badge bg-<?php echo $stockClass; ?> stock-quantity inline-editable"
                                                          data-field="stock"
                                                          data-product-id="<?php echo $product['id']; ?>"
                                                          data-product-unit-id="<?php echo $currentProductUnitId; ?>"
                                                          data-has-units="<?php echo $hasUnits ? '1' : '0'; ?>"
                                                          data-raw-value="<?php echo $currentStock; ?>"
                                                          title="کلیک بکە بۆ دەستکاری">
                                                        <?php echo number_format($currentStock); ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted min-stock">
                                                        کەمترین: <?php echo number_format($currentMinStock); ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td data-label="بەروار">
                                                <?php $rawExpiry = $product['expiry_date'] ? date('Y-m-d', strtotime($product['expiry_date'])) : ''; ?>
                                                <span class="inline-editable <?php echo $isExpired ? 'text-danger' : 'text-muted'; ?>"
                                                      data-field="expiry_date"
                                                      data-product-id="<?php echo $product['id']; ?>"
                                                      data-raw-value="<?php echo $rawExpiry; ?>"
                                                      title="کلیک بکە بۆ دەستکاری">
                                                    <?php echo $product['expiry_date'] ? date('Y/m/d', strtotime($product['expiry_date'])) : '-'; ?>
                                                </span>
                                            </td>
                                            <td data-label="دۆخ">
                                                <span class="badge bg-<?php echo $stockClass; ?>">
                                                    <?php echo $stockStatus; ?>
                                                </span>
                                            </td>
                                            <td data-label="کردارەکان">
                                                <div class="products-row-actions" role="group">
                                                    <a href="<?php echo url('user/products/edit.php?id=' . $product['id'] . '&return_url=' . urlencode($currentListUrl)); ?>" 
                                                       class="btn btn-outline-primary" title="دەستکاری">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-outline-info" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#viewModal<?php echo $product['id']; ?>" 
                                                            title="بینین">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($product['barcode']): ?>
                                                        <a href="<?php echo url('user/products/barcode/index.php?barcode=' . urlencode($product['barcode']) . '&name=' . urlencode($product['name']) . '&price=' . urlencode($sellPriceRaw) . '&currency=' . urlencode($unitCurrency)); ?>" 
                                                           class="btn btn-outline-warning" 
                                                           target="_blank"
                                                           title="چاپکردنی بارکۆد">
                                                            <i class="bi bi-upc-scan"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="<?php echo url('user/products/shelf_price_tags/print.php?product_id=' . $product['id']); ?>" 
                                                       class="btn btn-outline-success" 
                                                       target="_blank"
                                                       title="چاپکردنی نرخی سەر ڕەفە">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
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
        </form>

        <!-- Pagination -->
        <?php if ($show_all): ?>
            <div class="pl-pagination">
                <div class="pl-page-info">
                    <i class="bi bi-list-ul"></i>
                    نیشاندانی هەموو کاڵاکان — <?php echo number_format($totalProducts); ?> کاڵا
                </div>
                <a href="?page=1&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&filter=<?php echo $stock_filter; ?>"
                   class="pl-btn pl-btn-ghost">
                    <i class="bi bi-grid-3x3-gap"></i> بگەڕێوە بە پەڕەکردن
                </a>
            </div>
        <?php elseif ($totalPages > 1): ?>
            <nav class="pl-pagination" aria-label="پەڕەکان">
                <div class="pl-page-info">پەڕەی <?php echo (int) $page; ?> لە <?php echo (int) $totalPages; ?> · <?php echo number_format($totalProducts); ?> کاڵا</div>
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&filter=<?php echo $stock_filter; ?>">
                            پێشوو
                        </a>
                    </li>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&filter=<?php echo $stock_filter; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&filter=<?php echo $stock_filter; ?>">
                            دواتر
                        </a>
                    </li>
                </ul>
                <a href="?page=1&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&filter=<?php echo $stock_filter; ?>&show_all=1"
                   class="pl-btn pl-btn-ghost">
                    <i class="bi bi-list-ul"></i> نیشاندانی هەموو
                </a>
            </nav>
        <?php elseif ($totalProducts > 0): ?>
            <div class="pl-pagination">
                <div class="pl-page-info">نیشاندانی <?php echo number_format($totalProducts); ?> کاڵا</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Form -->
    <form method="POST" id="deleteForm" action="<?php echo url('user/products/delete.php'); ?>" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="id" id="deleteProductId">
        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($currentListUrl); ?>">
    </form>

    <input type="file" id="productImageFileInput" class="d-none" accept="image/*">

    <!-- View Modals -->
    <?php foreach ($products as $product): ?>
        <div class="modal fade" id="viewModal<?php echo $product['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-box-seam"></i> 
                            <?php echo htmlspecialchars($product['name']); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4">
                                <?php if ($product['image_path']): ?>
                                    <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                         alt="Product Image" 
                                         class="img-fluid rounded">
                                <?php else: ?>
                                    <div class="bg-body-secondary rounded d-flex align-items-center justify-content-center" 
                                         style="height: 200px;">
                                        <i class="bi bi-box-seam display-3 text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-8">
                                <table class="table table-borderless">
                                    <tr>
                                        <th>بارکۆد:</th>
                                        <td><?php echo $product['barcode'] ?: '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>کەتەلۆگ:</th>
                                        <td><?php echo $product['category_name'] ?: '-'; ?></td>
                                    </tr>
                                    <?php
                                    if (!empty($isCurtainShopMode)) {
                                        $fabricSizeText = formatCurtainFabricSize(
                                            $product['fabric_width'] ?? null,
                                            $product['fabric_height'] ?? null,
                                            $product['fabric_measure_unit'] ?? 'cm'
                                        );
                                        if ($fabricSizeText !== '') {
                                            echo '<tr><th>قیاسی قوماش:</th><td>' . htmlspecialchars($fabricSizeText) . '</td></tr>';
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($product['units'])): ?>
                                        <tr>
                                            <th>یەکەکان:</th>
                                            <td>
                                                <?php foreach ($product['units'] as $unit): ?>
                                                    <div class="mb-2">
                                                        <strong><?php echo htmlspecialchars($unit['unit_name']); ?></strong>
                                                        <?php if ($unit['unit_symbol']): ?>
                                                            (<?php echo htmlspecialchars($unit['unit_symbol']); ?>)
                                                        <?php endif; ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php 
                                                            $unitCurrency = $unit['currency'] ?? 'IQD';
                                                            ?>
                                                            کڕین: <?php echo formatMoney($unit['buy_price'], $unitCurrency); ?> | 
                                                            فرۆشتن: <?php echo formatMoney($unit['sell_price'], $unitCurrency); ?>
                                                            <?php if ($unit['wholesale_price'] > 0): ?>
                                                                | جوملە: <?php echo formatMoney($unit['wholesale_price'], $unitCurrency); ?>
                                                            <?php endif; ?>
                                                            <?php if ($unit['special_price'] > 0): ?>
                                                                | تایبەت: <?php echo formatMoney($unit['special_price'], $unitCurrency); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                        <br>
                                                        <small class="text-info">
                                                            بەردەست: <?php echo number_format($unit['stock_quantity']); ?> | 
                                                            کەمترین: <?php echo number_format($unit['min_stock']); ?>
                                                        </small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <th>نرخی کڕین:</th>
                                            <td><?php echo formatMoney($product['buy_price']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>نرخی فرۆشتن:</th>
                                            <td><?php echo formatMoney($product['sell_price']); ?></td>
                                        </tr>
                                        <?php if ($product['wholesale_price'] > 0): ?>
                                        <tr>
                                            <th>نرخی جوملە:</th>
                                            <td><?php echo formatMoney($product['wholesale_price']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($product['special_price'] > 0): ?>
                                        <tr>
                                            <th>نرخی تایبەت:</th>
                                            <td><?php echo formatMoney($product['special_price']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <th>بەردەست:</th>
                                            <td><?php echo number_format($product['stock_quantity']); ?> دانە</td>
                                        </tr>
                                        <tr>
                                            <th>کەمترین بەردەست:</th>
                                            <td><?php echo number_format($product['min_stock']); ?> دانە</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ($product['expiry_date']): ?>
                                    <tr>
                                        <th>بەروار بەسەرچوون:</th>
                                        <td><?php echo date('Y/m/d', strtotime($product['expiry_date'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($product['custom_fields'])): ?>
                                        <tr>
                                            <th>زانیارییە زیادەکان:</th>
                                            <td>
                                                <?php foreach ($product['custom_fields'] as $customField): ?>
                                                    <div class="mb-1">
                                                        <strong><?php echo htmlspecialchars($customField['field_name'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                                        <?php
                                                        $customValue = $customField['display_value'] ?? $customField['value'];
                                                        echo $customValue === null || $customValue === ''
                                                            ? '<span class="text-muted">-</span>'
                                                            : htmlspecialchars((string)$customValue, ENT_QUOTES, 'UTF-8');
                                                        ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>دروستکراوە:</th>
                                        <td><?php echo date('Y/m/d H:i', strtotime($product['created_at'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="<?php echo url('user/products/edit.php?id=' . $product['id']); ?>" 
                           class="btn btn-primary">
                            <i class="bi bi-pencil"></i> دەستکاری
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            داخستن
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Print Columns Selection Modal -->
    <div class="modal fade" id="printColumnsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-printer"></i> هەڵبژاردنی ستونەکان بۆ چاپکردن
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">تکایە ستونەکانی دەتەوێت لە چاپەکەدا دەربکەوێت هەڵبژێرە:</p>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="name" id="col_name" checked>
                                <label class="form-check-label" for="col_name">
                                    ناوی کاڵا
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="image" id="col_image" checked>
                                <label class="form-check-label" for="col_image">
                                    وێنەی کاڵا
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="category" id="col_category" checked>
                                <label class="form-check-label" for="col_category">
                                    کەتەلۆگ
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="barcode" id="col_barcode" checked>
                                <label class="form-check-label" for="col_barcode">
                                    بارکۆد
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="unit" id="col_unit" checked>
                                <label class="form-check-label" for="col_unit">
                                    یەکە
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="sell_price" id="col_sell_price" checked>
                                <label class="form-check-label" for="col_sell_price">
                                    نرخی فرۆشتن
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="wholesale_price" id="col_wholesale_price" checked>
                                <label class="form-check-label" for="col_wholesale_price">
                                    نرخی جوملە
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="special_price" id="col_special_price" checked>
                                <label class="form-check-label" for="col_special_price">
                                    نرخی تایبەت
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="stock" id="col_stock" checked>
                                <label class="form-check-label" for="col_stock">
                                    بەردەست
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="date" id="col_date" checked>
                                <label class="form-check-label" for="col_date">
                                    بەروار
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input column-checkbox" type="checkbox" value="status" id="col_status" checked>
                                <label class="form-check-label" for="col_status">
                                    دۆخ
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllColumns">
                            <i class="bi bi-check-all"></i> هەموو هەڵبژێرە
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllColumns">
                            <i class="bi bi-x-square"></i> هیچ هەڵمەژێرە
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                    <button type="button" class="btn btn-primary" id="printWithColumns">
                        <i class="bi bi-printer"></i> چاپکردن
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Panel for Selected Products -->
    <button type="button" id="toggleSelectedPanel" title="بینینی کاڵا هەڵبژێردراوەکان">
        <i class="bi bi-list-check"></i>
        <span class="badge" id="toggleBadge">0</span>
    </button>

    <div id="selectedProductsPanel">
        <div class="panel-header">
            <h5>
                <i class="bi bi-check2-square"></i>
                کاڵا هەڵبژێردراوەکان
                <span class="badge" id="panelBadge">0</span>
            </h5>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="clearAllInPanel" title="سڕینەوەی هەموو کاڵا هەڵبژێردراوەکان">
                <i class="bi bi-trash"></i> سڕینەوەی هەموو
            </button>
            <button type="button" class="btn-close-panel" id="closePanel">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="panel-body" id="selectedProductsList">
            <div class="panel-empty">
                <i class="bi bi-inbox"></i>
                <p>هیچ کاڵایەک هەڵنەبژێردراوە</p>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
    <script>
        const CSRF_TOKEN = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
        const QUICK_UPDATE_URL = <?php echo json_encode(url('user/products/api/quick_update.php'), JSON_UNESCAPED_UNICODE); ?>;
        const PRODUCT_IMAGE_UPLOAD_URL = <?php echo json_encode(url('user/products/api/upload_image.php'), JSON_UNESCAPED_UNICODE); ?>;

        let activeInlineEditor = null;
        let pendingImageProductId = null;

        function calculateImageDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
            let width = originalWidth;
            let height = originalHeight;
            const aspectRatio = originalWidth / originalHeight;

            if (width > maxWidth) {
                width = maxWidth;
                height = width / aspectRatio;
            }
            if (height > maxHeight) {
                height = maxHeight;
                width = height * aspectRatio;
            }

            return { width: Math.round(width), height: Math.round(height) };
        }

        function compressProductImage(file, callback) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    const { width, height } = calculateImageDimensions(img.width, img.height, 800, 800);

                    canvas.width = width;
                    canvas.height = height;
                    ctx.drawImage(img, 0, 0, width, height);

                    canvas.toBlob(function(blob) {
                        const compressedFile = new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });
                        callback(compressedFile);
                    }, 'image/jpeg', 0.8);
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        function setProductImageUploading(trigger, isUploading) {
            if (!trigger) return;
            trigger.classList.toggle('is-uploading', isUploading);
            const overlay = trigger.querySelector('.product-image-overlay');
            if (overlay) {
                overlay.innerHTML = isUploading
                    ? '<span class="spinner-border spinner-border-sm" role="status"></span>'
                    : '<i class="bi bi-camera-fill"></i>';
            }
        }

        function updateProductImageInDom(productId, imageUrl) {
            const trigger = document.querySelector(`.product-image-upload-trigger[data-product-id="${productId}"]`);
            if (trigger) {
                let img = trigger.querySelector('.product-image-thumb');
                if (!img) {
                    const placeholder = trigger.querySelector('.product-image-placeholder');
                    if (placeholder) {
                        placeholder.remove();
                    }
                    img = document.createElement('img');
                    img.className = 'rounded product-image-thumb';
                    img.alt = 'Product Image';
                    trigger.insertBefore(img, trigger.querySelector('.product-image-overlay'));
                }
                img.src = imageUrl;
            }

            const checkbox = document.querySelector(`.product-checkbox[data-product-id="${productId}"]`);
            if (checkbox) {
                checkbox.dataset.productImage = imageUrl;
            }

            const saved = localStorage.getItem('selectedProducts');
            if (saved) {
                try {
                    const selectedProducts = JSON.parse(saved);
                    if (!Array.isArray(selectedProducts) && selectedProducts[productId]) {
                        selectedProducts[productId].image = imageUrl;
                        localStorage.setItem('selectedProducts', JSON.stringify(selectedProducts));
                    }
                } catch (e) {
                    // ignore parse errors
                }
            }

            const viewModal = document.getElementById(`viewModal${productId}`);
            if (viewModal) {
                const modalImageCol = viewModal.querySelector('.col-md-4');
                if (modalImageCol) {
                    modalImageCol.innerHTML = '';
                    const modalImg = document.createElement('img');
                    modalImg.src = imageUrl;
                    modalImg.alt = 'Product Image';
                    modalImg.className = 'img-fluid rounded';
                    modalImageCol.appendChild(modalImg);
                }
            }

            if (typeof updateSelectedProductsPanel === 'function') {
                updateSelectedProductsPanel();
            }
        }

        async function uploadProductImage(productId, file) {
            const trigger = document.querySelector(`.product-image-upload-trigger[data-product-id="${productId}"]`);
            setProductImageUploading(trigger, true);

            try {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('product_id', String(productId));
                formData.append('product_image', file);

                const response = await fetch(PRODUCT_IMAGE_UPLOAD_URL, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'هەڵە لە بارکردنی وێنە');
                }

                const imageUrl = data.data?.image_url;
                if (!imageUrl) {
                    throw new Error('وەڵامی سێرڤەر ناتەواوە');
                }

                updateProductImageInDom(productId, imageUrl);
            } catch (error) {
                alert(error.message || 'هەڵە لە بارکردنی وێنە');
            } finally {
                setProductImageUploading(trigger, false);
            }
        }

        document.addEventListener('click', function(event) {
            const trigger = event.target.closest('.product-image-upload-trigger');
            if (!trigger || trigger.classList.contains('is-uploading')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const productId = trigger.dataset.productId;
            if (!productId) {
                return;
            }

            pendingImageProductId = productId;
            const fileInput = document.getElementById('productImageFileInput');
            if (fileInput) {
                fileInput.value = '';
                fileInput.click();
            }
        });

        document.getElementById('productImageFileInput')?.addEventListener('change', function() {
            const file = this.files && this.files[0];
            const productId = pendingImageProductId;
            pendingImageProductId = null;
            this.value = '';

            if (!file || !productId) {
                return;
            }

            if (!file.type.startsWith('image/')) {
                alert('تکایە تەنها فایلی وێنە هەڵبژێرە');
                return;
            }

            compressProductImage(file, function(compressedFile) {
                uploadProductImage(productId, compressedFile);
            });
        });

        // Functions to save and load selected products from localStorage
        function getSelectedProductsFromStorage() {
            const saved = localStorage.getItem('selectedProducts');
            if (!saved) {
                return {};
            }

            try {
                const savedData = JSON.parse(saved);
                if (Array.isArray(savedData)) {
                    const normalized = {};
                    savedData.forEach(id => {
                        normalized[String(id)] = { id: String(id) };
                    });
                    return normalized;
                }
                return savedData || {};
            } catch (e) {
                console.error('Error parsing saved selections', e);
                return {};
            }
        }

        function getSelectedProductsCount() {
            return Object.keys(getSelectedProductsFromStorage()).length;
        }

        function syncBulkFormSelectedInputs() {
            const form = document.getElementById('bulkForm');
            if (!form) {
                return;
            }

            form.querySelectorAll('input.bulk-sync-input').forEach(el => el.remove());

            const selectedProducts = getSelectedProductsFromStorage();
            Object.keys(selectedProducts).forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_products[]';
                input.value = id;
                input.className = 'bulk-sync-input';
                form.appendChild(input);
            });
        }

        function updateBulkActionsBar() {
            const bulkActionsBar = document.getElementById('bulkActionsBar');
            const selectedCountSpan = document.getElementById('selectedCount');
            const selectAllCheckbox = document.getElementById('selectAll');
            const productCheckboxes = document.querySelectorAll('.product-checkbox');
            const totalSelectedCount = getSelectedProductsCount();
            const visibleCheckedCount = document.querySelectorAll('.product-checkbox:checked').length;

            if (selectedCountSpan) {
                selectedCountSpan.textContent = totalSelectedCount;
            }

            if (bulkActionsBar) {
                bulkActionsBar.style.display = 'block';
            }

            if (selectAllCheckbox && productCheckboxes.length > 0) {
                const allChecked = visibleCheckedCount === productCheckboxes.length;
                const someChecked = visibleCheckedCount > 0;
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = someChecked && !allChecked;
            } else if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            }

            syncBulkFormSelectedInputs();
        }

        function saveSelectedProducts() {
            let selectedProducts = getSelectedProductsFromStorage();

            // Update only products visible on the current page
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                const id = String(checkbox.value);
                if (checkbox.checked) {
                    if (!selectedProducts[id]) {
                        selectedProducts[id] = {
                            id: id,
                            name: checkbox.dataset.productName || '',
                            image: checkbox.dataset.productImage || '',
                            price: checkbox.dataset.productPrice || '0',
                            stock: checkbox.dataset.productStock || '0',
                            currency: checkbox.dataset.productCurrency || 'IQD'
                        };
                    }
                } else {
                    delete selectedProducts[id];
                }
            });

            localStorage.setItem('selectedProducts', JSON.stringify(selectedProducts));
            updateSelectedProductsPanel();
            updateBulkActionsBar();
        }

        function loadSelectedProducts() {
            const selectedProducts = getSelectedProductsFromStorage();
            const selectedIds = Object.keys(selectedProducts);

            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.checked = selectedIds.includes(String(checkbox.value));
            });

            updateBulkActionsBar();
            updateSelectedProductsPanel();
        }

        // Function to update selected products panel
        function updateSelectedProductsPanel() {
            const panelList = document.getElementById('selectedProductsList');
            const panelBadge = document.getElementById('panelBadge');
            const toggleBadge = document.getElementById('toggleBadge');
            const selectedProducts = getSelectedProductsFromStorage();
            const products = Object.values(selectedProducts);
            const count = products.length;

            if (panelBadge) {
                panelBadge.textContent = count;
            }

            if (toggleBadge) {
                toggleBadge.textContent = count;
                toggleBadge.classList.toggle('is-empty', count === 0);
            }

            if (!panelList) {
                return;
            }

            if (count === 0) {
                panelList.innerHTML = '<div class="panel-empty"><i class="bi bi-inbox"></i><p>هیچ کاڵایەک هەڵنەبژێردراوە</p></div>';
                return;
            }

            let html = '';
            products.forEach(product => {
                const imageHtml = product.image
                    ? `<img src="${product.image}" alt="${product.name}" class="product-image">`
                    : `<div class="product-image-placeholder"><i class="bi bi-box-seam"></i></div>`;

                html += `
                    <div class="selected-product-item" data-product-id="${product.id}">
                        <button type="button" class="btn-remove" onclick="removeProductFromPanel('${product.id}')" title="لابەرکردن">
                            <i class="bi bi-x"></i>
                        </button>
                        ${imageHtml}
                        <div class="product-info">
                            <div class="product-name">${product.name || 'کاڵای نەناسراو'}</div>
                            <div class="product-stock">بەردەست: ${formatNumber(product.stock || '0')}</div>
                        </div>
                    </div>
                `;
            });

            panelList.innerHTML = html;
        }

        // Function to remove product from panel
        function removeProductFromPanel(productId) {
            const productKey = String(productId);
            const checkbox = document.querySelector(`.product-checkbox[value="${productKey}"]`);
            if (checkbox) {
                checkbox.checked = false;
            }

            const selectedProducts = getSelectedProductsFromStorage();
            delete selectedProducts[productKey];
            localStorage.setItem('selectedProducts', JSON.stringify(selectedProducts));

            updateSelectedProductsPanel();
            updateBulkActionsBar();
        }

        // Function to toggle panel
        function toggleSelectedPanel() {
            const panel = document.getElementById('selectedProductsPanel');
            const toggleButton = document.getElementById('toggleSelectedPanel');
            
            if (panel.classList.contains('show')) {
                panel.classList.remove('show');
                panel.classList.add('hide');
                setTimeout(() => {
                    panel.style.display = 'none';
                    panel.classList.remove('hide');
                }, 300);
            } else {
                panel.style.display = 'flex';
                setTimeout(() => {
                    panel.classList.add('show');
                }, 10);
            }
        }

        // Drag functionality for selected products panel
        function initPanelDrag() {
            const panel = document.getElementById('selectedProductsPanel');
            const panelHeader = panel ? panel.querySelector('.panel-header') : null;
            const closeBtn = panel ? panel.querySelector('.btn-close-panel') : null;
            
            if (!panel || !panelHeader) return;

            let isDragging = false;
            let currentX = 0;
            let currentY = 0;
            let initialX = 0;
            let initialY = 0;
            let xOffset = 0;
            let yOffset = 0;
            let savePositionTimeout = null;

            // Load saved position from localStorage
            function loadPanelPosition() {
                const saved = localStorage.getItem('selectedProductsPanelPosition');
                if (saved) {
                    try {
                        const position = JSON.parse(saved);
                        if (position.top !== undefined && position.left !== undefined) {
                            // Apply saved position directly
                            panel.style.top = position.top + 'px';
                            panel.style.left = position.left + 'px';
                            xOffset = position.left;
                            yOffset = position.top;
                            
                            // Validate position when panel is visible
                            setTimeout(() => {
                                const panelRect = panel.getBoundingClientRect();
                                if (panelRect.width > 0 && panelRect.height > 0) {
                                    constrainPosition();
                                }
                            }, 100);
                        }
                    } catch (e) {
                        console.error('Error loading panel position:', e);
                        // Initialize with default position from CSS
                        const panelRect = panel.getBoundingClientRect();
                        if (panelRect.width > 0) {
                            xOffset = panelRect.left;
                            yOffset = panelRect.top;
                        } else {
                            // Use default CSS values
                            xOffset = 20;
                            yOffset = 80;
                        }
                    }
                } else {
                    // Initialize with default position
                    const panelRect = panel.getBoundingClientRect();
                    if (panelRect.width > 0) {
                        xOffset = panelRect.left;
                        yOffset = panelRect.top;
                    } else {
                        // Use default CSS values
                        xOffset = 20;
                        yOffset = 80;
                    }
                }
            }

            // Save position to localStorage (debounced)
            function savePanelPosition() {
                if (savePositionTimeout) {
                    clearTimeout(savePositionTimeout);
                }
                savePositionTimeout = setTimeout(() => {
                    const position = {
                        top: yOffset,
                        left: xOffset
                    };
                    localStorage.setItem('selectedProductsPanelPosition', JSON.stringify(position));
                }, 150);
            }

            // Constrain panel within viewport bounds
            function constrainPosition() {
                const panelRect = panel.getBoundingClientRect();
                const viewportWidth = window.innerWidth;
                const viewportHeight = window.innerHeight;
                const minDistance = 10;

                // Constrain X position
                if (xOffset < minDistance) {
                    xOffset = minDistance;
                } else if (xOffset + panelRect.width > viewportWidth - minDistance) {
                    xOffset = viewportWidth - panelRect.width - minDistance;
                }

                // Constrain Y position
                if (yOffset < minDistance) {
                    yOffset = minDistance;
                } else if (yOffset + panelRect.height > viewportHeight - minDistance) {
                    yOffset = viewportHeight - panelRect.height - minDistance;
                }

                panel.style.left = xOffset + 'px';
                panel.style.top = yOffset + 'px';
            }

            // Mouse drag handlers
            function dragStart(e) {
                // Don't start drag if clicking on close button
                if (closeBtn && (e.target === closeBtn || closeBtn.contains(e.target))) {
                    return;
                }

                if (e.type === 'touchstart') {
                    initialX = e.touches[0].clientX - xOffset;
                    initialY = e.touches[0].clientY - yOffset;
                } else {
                    initialX = e.clientX - xOffset;
                    initialY = e.clientY - yOffset;
                }

                if (e.type === 'mousedown') {
                    isDragging = true;
                    panelHeader.classList.add('dragging');
                    document.addEventListener('mousemove', drag);
                    document.addEventListener('mouseup', dragEnd);
                } else if (e.type === 'touchstart') {
                    isDragging = true;
                    panelHeader.classList.add('dragging');
                    document.addEventListener('touchmove', drag, { passive: false });
                    document.addEventListener('touchend', dragEnd);
                }
            }

            function drag(e) {
                if (!isDragging) return;

                e.preventDefault();

                if (e.type === 'touchmove') {
                    currentX = e.touches[0].clientX - initialX;
                    currentY = e.touches[0].clientY - initialY;
                } else {
                    currentX = e.clientX - initialX;
                    currentY = e.clientY - initialY;
                }

                xOffset = currentX;
                yOffset = currentY;

                constrainPosition();
            }

            function dragEnd(e) {
                if (!isDragging) return;

                isDragging = false;
                panelHeader.classList.remove('dragging');

                if (e.type === 'mouseup') {
                    document.removeEventListener('mousemove', drag);
                    document.removeEventListener('mouseup', dragEnd);
                } else if (e.type === 'touchend') {
                    document.removeEventListener('touchmove', drag);
                    document.removeEventListener('touchend', dragEnd);
                }

                savePanelPosition();
            }

            // Attach event listeners
            panelHeader.addEventListener('mousedown', dragStart);
            panelHeader.addEventListener('touchstart', dragStart, { passive: true });

            // Handle window resize to constrain panel position
            window.addEventListener('resize', () => {
                constrainPosition();
                savePanelPosition();
            });

            // Load saved position on initialization
            loadPanelPosition();
        }

        // Product search: live filter + clear button
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('productSearchInput');
            const clearSearchBtn = document.getElementById('clearProductSearch');
            const searchInputGroup = searchInput ? searchInput.closest('.product-search-input-group') : null;
            const filterForm = searchInput ? searchInput.closest('form') : null;

            if (!searchInput || !clearSearchBtn || !filterForm) {
                return;
            }

            const MIN_SEARCH_CHARS = 3;
            const DEBOUNCE_MS = 1200;
            let liveSearchTimer = null;
            // Honor tablet pen / handwriting uses IME composition; don't run
            // live search until the pen finishes writing the word.
            let isComposing = false;

            function toggleProductSearchClearBtn() {
                clearSearchBtn.classList.toggle('show', searchInput.value.trim().length > 0);
            }

            function getCurrentUrlSearch() {
                return (new URLSearchParams(window.location.search).get('search') || '').trim();
            }

            function buildLiveSearchParams(query) {
                const trimmed = query.trim();
                const urlParams = new URLSearchParams(window.location.search);

                if (trimmed.length >= MIN_SEARCH_CHARS) {
                    urlParams.set('search', trimmed);
                } else if (trimmed.length === 0) {
                    urlParams.delete('search');
                } else {
                    return null;
                }

                urlParams.set('page', '1');

                const category = filterForm.querySelector('[name="category"]').value;
                if (category) {
                    urlParams.set('category', category);
                } else {
                    urlParams.delete('category');
                }

                const stockFilter = filterForm.querySelector('[name="filter"]').value;
                if (stockFilter) {
                    urlParams.set('filter', stockFilter);
                } else {
                    urlParams.delete('filter');
                }

                return urlParams;
            }

            function shouldNavigateLiveSearch(query) {
                const trimmed = query.trim();
                const currentSearch = getCurrentUrlSearch();

                if (trimmed.length >= MIN_SEARCH_CHARS) {
                    return trimmed !== currentSearch;
                }
                if (trimmed.length === 0 && currentSearch !== '') {
                    return true;
                }
                return false;
            }

            function navigateLiveSearch(query) {
                const urlParams = buildLiveSearchParams(query);
                if (!urlParams || !shouldNavigateLiveSearch(query)) {
                    return;
                }

                if (searchInputGroup) {
                    searchInputGroup.classList.add('is-searching');
                }

                window.location.search = urlParams.toString();
            }

            function scheduleLiveSearch() {
                clearTimeout(liveSearchTimer);
                liveSearchTimer = setTimeout(function() {
                    navigateLiveSearch(searchInput.value);
                }, DEBOUNCE_MS);
            }

            searchInput.addEventListener('compositionstart', function() {
                isComposing = true;
                clearTimeout(liveSearchTimer);
            });

            searchInput.addEventListener('compositionend', function() {
                isComposing = false;
                toggleProductSearchClearBtn();
                scheduleLiveSearch();
            });

            searchInput.addEventListener('input', function(event) {
                toggleProductSearchClearBtn();

                // While the pen is still composing a word, wait for
                // compositionend before touching the URL / navigating.
                if (isComposing || event.isComposing) {
                    clearTimeout(liveSearchTimer);
                    return;
                }

                scheduleLiveSearch();
            });

            clearSearchBtn.addEventListener('click', function() {
                clearTimeout(liveSearchTimer);

                const hadActiveSearch = getCurrentUrlSearch() !== '';

                searchInput.value = '';
                toggleProductSearchClearBtn();

                if (hadActiveSearch) {
                    if (searchInputGroup) {
                        searchInputGroup.classList.add('is-searching');
                    }
                    const urlParams = buildLiveSearchParams('');
                    if (urlParams) {
                        window.location.search = urlParams.toString();
                    }
                    return;
                }

                searchInput.focus();
            });

            toggleProductSearchClearBtn();
        });

        // Bulk selection handling
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const productCheckboxes = document.querySelectorAll('.product-checkbox');
            const bulkActionsBar = document.getElementById('bulkActionsBar');
            const selectedCountSpan = document.getElementById('selectedCount');
            const bulkActionSelect = document.querySelector('select[name="bulk_action"]');
            const categorySelector = document.getElementById('categorySelector');
            const clearSelectionBtn = document.getElementById('clearSelection');
            const togglePanelBtn = document.getElementById('toggleSelectedPanel');
            const closePanelBtn = document.getElementById('closePanel');

            // Initialize panel drag functionality
            initPanelDrag();

            // Load saved selections from localStorage
            loadSelectedProducts();

            // Toggle panel button
            if (togglePanelBtn) {
                togglePanelBtn.addEventListener('click', function() {
                    toggleSelectedPanel();
                });
            }

            // Close panel button
            if (closePanelBtn) {
                closePanelBtn.addEventListener('click', function() {
                    toggleSelectedPanel();
                });
            }

            const clearAllInPanelBtn = document.getElementById('clearAllInPanel');

            // Select/deselect all (current page only)
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    productCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    saveSelectedProducts();
                });
            }

            // Individual checkbox change
            productCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    saveSelectedProducts();
                });
            });

            // Clear selection (shared by bulk bar and panel "Clear all" button)
            function clearAllSelectedProducts() {
                document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    checkbox.checked = false;
                });
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                }
                localStorage.setItem('selectedProducts', '{}');
                updateSelectedProductsPanel();
                updateBulkActionsBar();
            }
            if (clearSelectionBtn) clearSelectionBtn.addEventListener('click', clearAllSelectedProducts);

            // Panel "سڕینەوەی هەموو" – run same clear logic directly so it always works
            if (clearAllInPanelBtn) {
                clearAllInPanelBtn.addEventListener('click', function() {
                    clearAllSelectedProducts();
                });
            }

            // Show/hide category selector based on action
            if (bulkActionSelect) {
                bulkActionSelect.addEventListener('change', function() {
                    if (this.value === 'update_category') {
                        categorySelector.style.display = 'block';
                    } else {
                        categorySelector.style.display = 'none';
                    }
                });
            }
        });

        // Delete confirmation
        function confirmDelete(productId) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم کاڵایە؟')) {
                document.getElementById('deleteProductId').value = productId;
                document.getElementById('deleteForm').submit();
            }
        }

        // Form submission confirmation for bulk actions
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            syncBulkFormSelectedInputs();

            const action = document.querySelector('select[name="bulk_action"]').value;
            const selectedCount = getSelectedProductsCount();

            if (selectedCount === 0) {
                e.preventDefault();
                alert('تکایە لانی کەم یەک کاڵا هەڵبژێرە');
                return;
            }

            let confirmMessage = '';
            switch (action) {
                case 'delete':
                    confirmMessage = `ئایا دڵنیایت لە سڕینەوەی ${selectedCount} کاڵا؟`;
                    break;
                case 'reset_available_stock':
                    confirmMessage = `ئایا دڵنیایت لە "سفر کردنەوەی بڕی بەردەست" بۆ ${selectedCount} کاڵا؟ (بڕی بەردەست 0 دەکرێت)`;
                    break;
                case 'update_category':
                    confirmMessage = `ئایا دڵنیایت لە گۆڕینی کەتەلۆگی ${selectedCount} کاڵا؟`;
                    break;
                default:
                    e.preventDefault();
                    alert('تکایە کردارێک هەڵبژێرە');
                    return;
            }

            if (!confirm(confirmMessage)) {
                e.preventDefault();
            }
        });

        function renderInlineEditableDisplay(el, value) {
            const field = el.dataset.field;
            if (field === 'name') {
                el.textContent = value;
                return;
            }

            if (field === 'barcode') {
                if (value) {
                    el.innerHTML = '<code>' + escapeHtml(value) + '</code>';
                } else {
                    el.innerHTML = '<span class="text-muted">-</span>';
                }
                return;
            }

            if (field === 'buy_price' || field === 'sell_price') {
                const currency = el.dataset.currency || 'IQD';
                el.textContent = formatMoney(value, currency);
                return;
            }

            if (field === 'stock') {
                el.textContent = formatNumber(value);
                updateStockBadgeColor(el, parseFloat(value));
                updateProductStockStatus(productIdFromEl(el), parseFloat(value), el);
                return;
            }

            if (field === 'expiry_date') {
                el.classList.remove('text-danger', 'text-muted');
                if (value) {
                    const expiryTime = new Date(value + 'T00:00:00').getTime();
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    el.classList.add(expiryTime <= today.getTime() ? 'text-danger' : 'text-muted');
                    el.textContent = value.replace(/-/g, '/');
                } else {
                    el.classList.add('text-muted');
                    el.textContent = '-';
                }
            }
        }

        function productIdFromEl(el) {
            return el.dataset.productId;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function updateStockBadgeColor(stockEl, stockQuantity) {
            const stockDisplay = stockEl.closest('.stock-display');
            const minStock = stockDisplay ? parseFloat(stockDisplay.dataset.minStock || '0') : 0;

            stockEl.className = 'badge stock-quantity inline-editable';
            if (stockQuantity === 0) {
                stockEl.classList.add('bg-danger');
            } else if (stockQuantity <= minStock) {
                stockEl.classList.add('bg-warning');
            } else {
                stockEl.classList.add('bg-success');
            }
        }

        function updateProductStockStatus(productId, stockQuantity, stockEl) {
            const stockDisplay = stockEl.closest('.stock-display');
            const minStock = stockDisplay ? parseFloat(stockDisplay.dataset.minStock || '0') : 0;
            const statusElement = document.querySelector(`tr:has(input[value="${productId}"]) .badge:last-child`);
            if (!statusElement || statusElement === stockEl) return;

            statusElement.className = 'badge ';
            if (stockQuantity === 0) {
                statusElement.textContent = 'تەواو بووە';
                statusElement.className += 'bg-danger';
            } else if (stockQuantity <= minStock) {
                statusElement.textContent = 'کەمە';
                statusElement.className += 'bg-warning';
            } else {
                statusElement.textContent = 'بەردەستە';
                statusElement.className += 'bg-success';
            }

            const checkbox = document.querySelector(`.product-checkbox[data-product-id="${productId}"]`);
            if (checkbox) {
                checkbox.dataset.productStock = stockQuantity;
            }
        }

        function syncProductNameInDom(productId, name) {
            const checkbox = document.querySelector(`.product-checkbox[data-product-id="${productId}"]`);
            if (checkbox) {
                checkbox.dataset.productName = name;
            }

            const saved = localStorage.getItem('selectedProducts');
            if (saved) {
                try {
                    const selectedProducts = JSON.parse(saved);
                    if (!Array.isArray(selectedProducts) && selectedProducts[productId]) {
                        selectedProducts[productId].name = name;
                        localStorage.setItem('selectedProducts', JSON.stringify(selectedProducts));
                    }
                } catch (e) {
                    // ignore parse errors
                }
            }

            const viewModal = document.getElementById(`viewModal${productId}`);
            if (viewModal) {
                const title = viewModal.querySelector('.modal-title');
                if (title) {
                    title.innerHTML = '<i class="bi bi-box-seam"></i> ' + escapeHtml(name);
                }
            }

            const barcodeLink = document.querySelector(`tr:has(.product-checkbox[data-product-id="${productId}"]) a[href*="barcode/index.php"]`);
            if (barcodeLink) {
                try {
                    const url = new URL(barcodeLink.href);
                    url.searchParams.set('name', name);
                    barcodeLink.href = url.toString();
                } catch (e) {
                    // ignore invalid URL
                }
            }

            if (typeof updateSelectedProductsPanel === 'function') {
                updateSelectedProductsPanel();
            }
        }

        function cancelActiveInlineEdit() {
            const session = activeInlineEditor;
            if (!session || session.committing) return;
            const { el, originalValue } = session;
            el.dataset.rawValue = originalValue;
            renderInlineEditableDisplay(el, originalValue);
            el.classList.remove('is-editing', 'is-saving');
            activeInlineEditor = null;
        }

        async function commitActiveInlineEdit() {
            const session = activeInlineEditor;
            if (!session || session.committing) return;

            const { el, input, originalValue } = session;
            const field = el.dataset.field;
            let newValue = input.value.trim();

            if (field === 'name') {
                if (newValue === '') {
                    cancelActiveInlineEdit();
                    return;
                }
            } else if (field === 'barcode' || field === 'expiry_date') {
                // no extra validation (empty date clears the value)
            } else {
                if (newValue === '' || isNaN(newValue) || parseFloat(newValue) < 0) {
                    cancelActiveInlineEdit();
                    return;
                }
                newValue = String(parseFloat(newValue));
            }

            if (newValue === String(originalValue)) {
                renderInlineEditableDisplay(el, originalValue);
                el.classList.remove('is-editing', 'is-saving');
                activeInlineEditor = null;
                return;
            }

            session.committing = true;
            activeInlineEditor = null;

            el.classList.add('is-saving');
            el.classList.remove('is-editing');

            try {
                const response = await fetch(QUICK_UPDATE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        product_id: parseInt(el.dataset.productId, 10),
                        field: field,
                        value: (field === 'barcode' || field === 'name' || field === 'expiry_date') ? newValue : parseFloat(newValue),
                        product_unit_id: el.dataset.productUnitId ? parseInt(el.dataset.productUnitId, 10) : null
                    })
                });

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'هەڵە لە خەزنکردن');
                }

                const savedValue = data.data?.value ?? newValue;
                el.dataset.rawValue = String(savedValue);
                renderInlineEditableDisplay(el, savedValue);

                if (field === 'sell_price') {
                    const checkbox = document.querySelector(`.product-checkbox[data-product-id="${el.dataset.productId}"]`);
                    if (checkbox) {
                        checkbox.dataset.productPrice = savedValue;
                    }
                }

                if (field === 'name') {
                    syncProductNameInDom(el.dataset.productId, String(savedValue));
                }

                if (field === 'buy_price' || field === 'sell_price' || field === 'stock') {
                    syncUnitDropdownData(el.dataset.productId, field, savedValue);
                }
            } catch (error) {
                alert(error.message || 'هەڵە لە خەزنکردن');
                el.dataset.rawValue = originalValue;
                renderInlineEditableDisplay(el, originalValue);
            } finally {
                el.classList.remove('is-saving');
            }
        }

        function syncUnitDropdownData(productId, field, value) {
            const dropdown = document.querySelector(`select.unit-dropdown[data-product-id="${productId}"]`);
            if (!dropdown) return;

            const selectedOption = dropdown.options[dropdown.selectedIndex];
            if (!selectedOption) return;

            if (field === 'buy_price') {
                selectedOption.dataset.buyPrice = value;
            } else if (field === 'sell_price') {
                selectedOption.dataset.sellPrice = value;
            } else if (field === 'stock') {
                selectedOption.dataset.stock = value;
            }
        }

        function startInlineEdit(el) {
            if (el.classList.contains('is-editing') || el.classList.contains('is-saving')) {
                return;
            }

            const field = el.dataset.field;
            const originalValue = el.dataset.rawValue ?? '';
            const input = document.createElement('input');
            if (field === 'expiry_date') {
                input.type = 'date';
            } else if (field === 'barcode' || field === 'name') {
                input.type = 'text';
            } else {
                input.type = 'number';
            }
            input.className = 'form-control form-control-sm inline-editing-input';
            input.value = originalValue;

            if (field !== 'barcode' && field !== 'name' && field !== 'expiry_date') {
                input.min = '0';
                input.step = field === 'stock' ? '1' : '0.001';
            }

            el.classList.add('is-editing');
            el.innerHTML = '';
            el.appendChild(input);
            input.focus();
            input.select();

            let committed = false;
            const finish = (shouldSave) => {
                if (committed) return;
                committed = true;
                activeInlineEditor = { el, input, originalValue };
                if (shouldSave) {
                    commitActiveInlineEdit();
                } else {
                    cancelActiveInlineEdit();
                }
            };

            input.addEventListener('blur', () => finish(true));
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish(true);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            });

            activeInlineEditor = { el, input, originalValue, committing: false };
        }

        document.addEventListener('click', function(event) {
            const editable = event.target.closest('.inline-editable');
            if (!editable || editable.classList.contains('is-editing') || editable.classList.contains('is-saving')) {
                return;
            }
            event.stopPropagation();
            startInlineEdit(editable);
        });

        // Unit switching functionality
        function switchUnit(productId, unitIndex) {
            const dropdown = document.querySelector(`select[data-product-id="${productId}"]`);
            const selectedOption = dropdown.options[unitIndex];
            const currency = selectedOption.dataset.currency || 'IQD';
            
            // Update buy price display
            const buyPriceDisplay = document.querySelector(`.buy-price-display[data-product-id="${productId}"]`);
            const buyPriceElement = buyPriceDisplay ? buyPriceDisplay.querySelector('.buy-price') : null;
            
            if (buyPriceElement) {
                buyPriceElement.textContent = formatMoney(selectedOption.dataset.buyPrice, currency);
                buyPriceElement.dataset.rawValue = selectedOption.dataset.buyPrice;
                buyPriceElement.dataset.productUnitId = selectedOption.dataset.productUnitId || '';
                buyPriceElement.dataset.currency = currency;
            }
            
            // Update price display
            const priceDisplay = document.querySelector(`.price-display[data-product-id="${productId}"]`);
            const sellPriceElement = priceDisplay.querySelector('.sell-price');
            const additionalPricesElement = document.querySelector(`.additional-prices[data-product-id="${productId}"]`);
            
            if (sellPriceElement) {
                sellPriceElement.textContent = formatMoney(selectedOption.dataset.sellPrice, currency);
                sellPriceElement.dataset.rawValue = selectedOption.dataset.sellPrice;
                sellPriceElement.dataset.productUnitId = selectedOption.dataset.productUnitId || '';
                sellPriceElement.dataset.currency = currency;
            }
            
            // Update additional prices
            if (additionalPricesElement) {
                const wholesalePrice = selectedOption.dataset.wholesalePrice;
                const specialPrice = selectedOption.dataset.specialPrice;
                
                let additionalText = '';
                if (wholesalePrice > 0) {
                    additionalText += 'جوملە: ' + formatMoney(wholesalePrice, currency);
                }
                if (specialPrice > 0) {
                    if (additionalText) additionalText += ' | ';
                    additionalText += 'تایبەت: ' + formatMoney(specialPrice, currency);
                }
                
                if (additionalText) {
                    additionalPricesElement.innerHTML = '<small class="text-muted">' + additionalText + '</small>';
                    additionalPricesElement.style.display = 'block';
                } else {
                    additionalPricesElement.style.display = 'none';
                }
            }
            
            // Update stock display
            const stockDisplay = document.querySelector(`.stock-display[data-product-id="${productId}"]`);
            const stockQuantityElement = stockDisplay.querySelector('.stock-quantity');
            const minStockElement = stockDisplay.querySelector('.min-stock');
            
            if (stockQuantityElement) {
                stockQuantityElement.textContent = formatNumber(selectedOption.dataset.stock);
                stockQuantityElement.dataset.rawValue = selectedOption.dataset.stock;
                stockQuantityElement.dataset.productUnitId = selectedOption.dataset.productUnitId || '';

                const stockQuantity = parseFloat(selectedOption.dataset.stock);
                const minStock = parseFloat(selectedOption.dataset.minStock);
                updateStockBadgeColor(stockQuantityElement, stockQuantity);
                updateProductStockStatus(productId, stockQuantity, stockQuantityElement);
            }
            
            if (minStockElement) {
                minStockElement.textContent = 'کەمترین: ' + formatNumber(selectedOption.dataset.minStock);
            }

            if (stockDisplay) {
                stockDisplay.dataset.minStock = selectedOption.dataset.minStock;
            }
        }

        // Helper function to format money
        function formatMoney(amount, currency = 'IQD') {
            if (currency === 'USD') {
                return '$' + new Intl.NumberFormat('ku-Arab-IQ', {
                    style: 'decimal',
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                }).format(amount);
            }
            return new Intl.NumberFormat('ku-Arab-IQ', {
                style: 'decimal',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(amount);
        }

        // Helper function to format numbers
        function formatNumber(number) {
            return new Intl.NumberFormat('ku-Arab-IQ').format(number);
        }

        // Print Columns Modal Handling
        document.addEventListener('DOMContentLoaded', function() {
            const printColumnsModal = document.getElementById('printColumnsModal');
            const columnCheckboxes = document.querySelectorAll('.column-checkbox');
            const selectAllBtn = document.getElementById('selectAllColumns');
            const deselectAllBtn = document.getElementById('deselectAllColumns');
            const printBtn = document.getElementById('printWithColumns');

            // Load saved preferences from localStorage
            const savedColumns = localStorage.getItem('printColumns');
            if (savedColumns) {
                const saved = JSON.parse(savedColumns);
                columnCheckboxes.forEach(checkbox => {
                    checkbox.checked = saved.includes(checkbox.value);
                });
            }

            // Select all columns
            selectAllBtn.addEventListener('click', function() {
                columnCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
            });

            // Deselect all columns
            deselectAllBtn.addEventListener('click', function() {
                columnCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
            });

            // Print button click
            printBtn.addEventListener('click', function() {
                const selectedColumns = Array.from(columnCheckboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.value);

                // If no columns selected, show all by default
                if (selectedColumns.length === 0) {
                    alert('تکایە لانی کەم یەک ستون هەڵبژێرە');
                    return;
                }

                // Save preferences to localStorage
                localStorage.setItem('printColumns', JSON.stringify(selectedColumns));

                // Get selected product IDs from localStorage (stored as object: { id: {...} })
                const savedProducts = JSON.parse(localStorage.getItem('selectedProducts') || '{}');
                const selectedProductIds = (typeof savedProducts === 'object' && savedProducts !== null && !Array.isArray(savedProducts))
                    ? Object.keys(savedProducts)
                    : (Array.isArray(savedProducts) ? savedProducts : []);
                const productsParam = selectedProductIds.length > 0 ? selectedProductIds.join(',') : '';

                // Build URL with selected columns and products
                const baseUrl = '<?php echo url("user/products/print_a4.php"); ?>';
                const searchParam = '<?php echo urlencode($search); ?>';
                const categoryParam = '<?php echo $category_filter; ?>';
                const filterParam = '<?php echo $stock_filter; ?>';
                const colsParam = selectedColumns.join(',');
                
                let printUrl = `${baseUrl}?search=${searchParam}&category=${categoryParam}&filter=${filterParam}&cols=${colsParam}`;
                if (productsParam) {
                    printUrl += `&products=${productsParam}`;
                }
                
                // Close modal and open print page
                const modal = bootstrap.Modal.getInstance(printColumnsModal);
                modal.hide();
                
                window.open(printUrl, '_blank');
            });
        });
    </script>
</body>
</html>