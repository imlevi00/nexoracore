<?php
/**
 * چاپکردنی تێمپلێتی نرخی سەر ڕەفە - user/products/shelf_price_tags/print.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
requireShelfPriceTagsAccess();
$templateId = (int)($_GET['id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);

// If product_id is provided but template_id is not, use default template
if ($productId && !$templateId) {
    $defaultStmt = $conn->prepare("SELECT * FROM shelf_price_tags WHERE user_id = ? AND is_default = 1 LIMIT 1");
    $defaultStmt->bind_param("i", $currentUser['id']);
    $defaultStmt->execute();
    $defaultResult = $defaultStmt->get_result();
    
    if ($defaultResult->num_rows > 0) {
        $template = $defaultResult->fetch_assoc();
        $templateId = $template['id'];
    } else {
        // If no default template, use the first template
        $firstStmt = $conn->prepare("SELECT * FROM shelf_price_tags WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
        $firstStmt->bind_param("i", $currentUser['id']);
        $firstStmt->execute();
        $firstResult = $firstStmt->get_result();
        
        if ($firstResult->num_rows > 0) {
            $template = $firstResult->fetch_assoc();
            $templateId = $template['id'];
        } else {
            setMessage('هیچ تێمپلێتێکت نییە. تکایە یەکێک دروست بکە', 'error');
            redirect(url('user/products/shelf_price_tags/index.php'));
        }
        $firstStmt->close();
    }
    $defaultStmt->close();
} else {
    // وەرگرتنی زانیاری تێمپلێت
    $stmt = $conn->prepare("SELECT * FROM shelf_price_tags WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $templateId, $currentUser['id']);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    
    if (!$template) {
        setMessage('تێمپلێت نەدۆزرایەوە', 'error');
        redirect(url('user/products/shelf_price_tags/index.php'));
    }
    $stmt->close();
}

if (!$templateId) {
    setMessage('ID ی تێمپلێت پێویستە', 'error');
    redirect(url('user/products/shelf_price_tags/index.php'));
}

// وەرگرتنی دەقەکان
$itemsStmt = $conn->prepare("
    SELECT * FROM shelf_price_tag_items 
    WHERE template_id = ?
    ORDER BY display_order ASC, id ASC
");
$itemsStmt->bind_param("i", $templateId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// وەرگرتنی ڕێکخستنەکان
$settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param("i", $currentUser['id']);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

// Get product info if provided
$productData = null;
$productUnit = null;
if ($productId) {
    $productStmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND user_id = ?");
    $productStmt->bind_param("ii", $productId, $currentUser['id']);
    $productStmt->execute();
    $productResult = $productStmt->get_result();
    if ($productResult->num_rows > 0) {
        $productData = $productResult->fetch_assoc();
        
        // Get product units if product has units
        $unitsStmt = $conn->prepare("
            SELECT pu.*, u.name as unit_name, u.symbol as unit_symbol
            FROM product_units pu
            INNER JOIN units u ON pu.unit_id = u.id
            WHERE pu.product_id = ?
            ORDER BY pu.is_primary DESC, pu.id ASC
            LIMIT 1
        ");
        $unitsStmt->bind_param("i", $productId);
        $unitsStmt->execute();
        $unitsResult = $unitsStmt->get_result();
        if ($unitsResult->num_rows > 0) {
            $productUnit = $unitsResult->fetch_assoc();
        }
        $unitsStmt->close();
    }
    $productStmt->close();
}

$pageTitle = "نرخی سەر ڕەفە - " . htmlspecialchars($template['name']);
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="<?php echo url('assets/css/style.css'); ?>" rel="stylesheet">
    
    <style>
        @media print {
            body * { visibility: hidden; }
            .price-tag-container, .price-tag-container * { visibility: visible; }
            .price-tag-container { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 100%; 
            }
            .no-print { display: none !important; }
            .price-tag { 
                border: none !important; 
                box-shadow: none !important;
                page-break-inside: avoid;
                margin-bottom: 10mm;
            }
            .price-tag-footer {
                border-top: 1px solid #ddd !important;
                margin-top: 10px !important;
                padding-top: 8px !important;
            }
            .price-tag-footer div {
                color: #000 !important;
            }
            @page {
                margin: 0;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .price-tag,
            html[data-bs-theme='dark'] .price-tag *,
            html[data-bs-theme='dark'] .price-tag-footer {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #000000 !important;
                box-shadow: none !important;
            }
        }
        
        .price-tag {
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
        
        .price-tag-item {
            margin-bottom: 8px;
            word-break: break-word;
        }
        
        .price-tag-item:last-child {
            margin-bottom: 0;
        }
        
        /* Template-specific styles */
        .template-simple .price-tag-item {
            text-align: center;
        }
        
        .template-detailed .price-tag-item {
            text-align: center;
        }
        
        .template-professional {
            text-align: center;
        }
        
        .template-professional .business-logo {
            max-width: 60px;
            max-height: 60px;
            margin: 0 auto 10px;
            display: block;
        }
        
        .template-professional .price-large {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        @media print {
            .price-tag {
                font-size: 10px;
                border: 2px solid #000;
                box-shadow: none;
            }
            .price-tag-footer {
                border-top: 1px solid #ddd !important;
                margin-top: 8px !important;
                padding-top: 6px !important;
            }
            .price-tag-footer div:first-child {
                font-size: 8px !important;
                font-weight: bold !important;
                color: #000 !important;
                margin-bottom: 3px !important;
            }
            .price-tag-footer div:last-child {
                font-size: 7px !important;
                font-weight: 600 !important;
                color: #000 !important;
                letter-spacing: 0.5px !important;
            }
            @page {
                size: 70mm auto;
                margin: 0;
            }
        }
    </style>
</head>
<body class="bg-body-secondary">
    <!-- Navigation -->
    <?php include_once '../../../includes/navigation.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <h2><i class="bi bi-tag"></i> <?php echo htmlspecialchars($template['name']); ?></h2>
                    <div>
                        <button class="btn btn-primary me-2" onclick="window.print()">
                            <i class="bi bi-printer"></i> چاپکردن
                        </button>
                        <a href="<?php echo url('user/products/shelf_price_tags/index.php'); ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                    </div>
                </div>
                
                <!-- Price Tag Container -->
                <div class="price-tag-container">
                    <div class="price-tag template-<?php echo htmlspecialchars($template['template_type']); ?>">
                        <?php if ($template['template_type'] === 'professional' && $settings && $settings['business_logo']): ?>
                            <img src="<?php echo url('uploads/' . $settings['business_logo']); ?>" 
                                 alt="Logo" class="business-logo">
                        <?php endif; ?>
                        
                        <?php 
                        foreach ($items as $item): 
                            // Replace placeholders with actual values
                            $content = $item['text_content'];
                            
                            if ($productData) {
                                // Determine price to use (unit price if available, otherwise main product price)
                                $sellPrice = $productUnit ? $productUnit['sell_price'] : $productData['sell_price'];
                                $currency = $productUnit ? ($productUnit['currency'] ?? 'IQD') : 'IQD';
                                
                                // Format price based on currency
                                $formattedPrice = formatMoney($sellPrice, $currency);
                                
                                $content = str_replace('{product_name}', htmlspecialchars($productData['name']), $content);
                                $content = str_replace('{price}', $formattedPrice, $content);
                                $content = str_replace('{barcode}', htmlspecialchars($productData['barcode'] ?? ''), $content);
                            } else {
                                $content = str_replace('{product_name}', 'ناوی کاڵا', $content);
                                $content = str_replace('{price}', '10,000 د', $content);
                                $content = str_replace('{barcode}', '1234567890', $content);
                            }
                            
                            $content = str_replace('{date}', date('Y/m/d'), $content);
                            $content = str_replace('{business_name}', htmlspecialchars($currentUser['business_name']), $content);
                            
                            // Special handling for price type
                            if ($item['text_type'] === 'price' && $productData) {
                                $sellPrice = $productUnit ? $productUnit['sell_price'] : $productData['sell_price'];
                                $currency = $productUnit ? ($productUnit['currency'] ?? 'IQD') : 'IQD';
                                $content = formatMoney($sellPrice, $currency);
                            }
                            
                            // Determine if this is a large price (for professional template)
                            $isLargePrice = ($template['template_type'] === 'professional' && $item['text_type'] === 'price');
                        ?>
                            <div class="price-tag-item <?php echo $isLargePrice ? 'price-large' : ''; ?>" 
                                 style="font-size: <?php echo $item['font_size']; ?>px; 
                                        font-weight: <?php echo $item['font_weight']; ?>; 
                                        text-align: <?php echo $item['text_align']; ?>;">
                                <?php echo nl2br(htmlspecialchars($content)); ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Footer -->
                        <div class="price-tag-footer" style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center;">
                            <div style="font-size: 9px; font-weight: bold; color: #000; margin-bottom: 4px;">
                                سیستەمی NexoraCore
                            </div>
                            <div style="font-size: 8px; font-weight: 600; color: #000; letter-spacing: 0.5px;">
                                nexoracore.com
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="text-center mt-4 no-print">
                    <button class="btn btn-success me-2" onclick="window.print()">
                        <i class="bi bi-printer"></i> چاپکردن
                    </button>
                    <a href="<?php echo url("user/products/shelf_price_tags/edit.php?id=$templateId"); ?>" class="btn btn-info me-2">
                        <i class="bi bi-pencil"></i> دەستکاری
                    </a>
                    <a href="<?php echo url('user/products/shelf_price_tags/index.php'); ?>" class="btn btn-secondary">
                        <i class="bi bi-list"></i> لیستی تێمپلێتەکان
                    </a>
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
