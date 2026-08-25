<?php
/**
 * بەڕێوەبردنی ڕێژەی ئاڵوگۆڕکردنی دراوە - user/settings/currency_exchange.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        // وەرگرتنی نرخی تەواو یان زیادکراوە
        $fullRateInput = isset($_POST['usd_to_iqd_rate']) && trim($_POST['usd_to_iqd_rate']) !== '' ? (float)($_POST['usd_to_iqd_rate']) : null;
        $manualAdjustmentInput = isset($_POST['manual_adjustment']) && trim($_POST['manual_adjustment']) !== '' ? (float)($_POST['manual_adjustment']) : null;
        
        // وەرگرتنی نرخی بنەڕەتی بۆ حسابکردن
        $currentBaseRate = getBaseExchangeRateFromDollarPrices();
        
        // پێشەکی: ئەگەر هەردووکیان هەبێت، زیادکراوەکە بەکاربهێنە
        if ($manualAdjustmentInput !== null) {
            // کاتێک بەکارهێنەر زیادکراوەکە دایبنێت
            if (setManualAdjustment($userId, $manualAdjustmentInput)) {
                $success = 'زیادکراوەی دەستی بە سەرکەوتوویی تۆمارکرا';
                writeLog("Manual adjustment updated by user {$currentUser['email']}: {$manualAdjustmentInput} IQD");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە تۆمارکردنی زیادکراوە';
            }
        } elseif ($fullRateInput !== null && $fullRateInput > 0) {
            // کاتێک بەکارهێنەر نرخی تەواو دایبنێت
            // حسابکردنی manual_adjustment لە جیاوازی لە نێوان نرخی دایبنراو و نرخی بنەڕەتی
            if (setExchangeRate($userId, 'USD', 'IQD', $fullRateInput)) {
                $success = 'ڕێژەی ئاڵوگۆڕکردن بە سەرکەوتوویی تۆمارکرا';
                writeLog("Exchange rate updated by user {$currentUser['email']}: 1 USD = {$fullRateInput} IQD");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە تۆمارکردنی ڕێژە';
            }
        } else {
            $error = 'تکایە نرخی دروست بنووسە';
        }
    }
}

// وەرگرتنی نرخی بنەڕەتی لە dollar_prices
$baseRate = getBaseExchangeRateFromDollarPrices();

// وەرگرتنی زیادکراوەی دەستی
$manualAdjustment = getManualAdjustment($userId);

// وەرگرتنی ڕێژەی ئێستا
$currentRate = getExchangeRate($userId, 'USD', 'IQD');
if ($currentRate === false || $currentRate == 1400.0) {
    $currentRate = null; // No custom rate set yet
}

// حسابکردنی کۆی گشتی
$totalRate = $baseRate + $manualAdjustment;

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ڕێژەی ئاڵوگۆڕکردنی دراوە - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('user/settings/settings.css'); ?>" rel="stylesheet">

    <style>
        .rate-bento-tile {
            background: var(--surface-2);
            border: 1px solid var(--border-default);
            border-radius: 16px;
            padding: 1.25rem 1rem;
            text-align: center;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .rate-bento-tile:hover {
            transform: translateY(-2px);
            border-color: rgba(79, 70, 229, 0.35);
        }
        .rate-bento-highlight {
            background: color-mix(in srgb, var(--brand) 8%, var(--surface-1));
            border-color: var(--brand);
        }
        .rate-val-main {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--brand);
            letter-spacing: -0.02em;
            margin: 0.25rem 0;
        }
    </style>
</head>
<body class="settings-module-page settings-page">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="settings-header-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <nav class="small text-muted mb-2" aria-label="breadcrumb">
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                        <span class="mx-2">/</span>
                        <a href="<?php echo url('user/settings/main.php'); ?>" class="text-decoration-none text-muted">
                            ڕێکخستنەکان
                        </a>
                        <span class="mx-2">/</span>
                        <span class="text-primary fw-medium">ڕێژەی ئاڵوگۆڕکردن</span>
                    </nav>
                    <div class="d-flex align-items-center gap-3">
                        <div class="settings-icon section-dollar-price" style="width:52px;height:52px;font-size:24px;">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div>
                            <h2 class="mb-0">ڕێژەی ئاڵوگۆڕکردنی دراوە</h2>
                            <p class="text-muted small mb-0">دانانی نرخی گۆڕینەوەی دۆلار بۆ دینار و زیادکراوەی دەستی</p>
                        </div>
                    </div>
                </div>
                <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <!-- Exchange Rate Card -->
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="settings-card card p-4 p-md-5">

                    <!-- Rate Information Display -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <div class="rate-bento-tile">
                                <span class="text-muted small mb-1">نرخی ئۆتۆماتیکی (سیستەم):</span>
                                <div class="h4 fw-bold text-primary mb-1">
                                    <?php echo number_format($baseRate, 0); ?> دینار
                                </div>
                                <span class="text-muted" style="font-size: 0.75rem;">(لە dollar_prices)</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="rate-bento-tile">
                                <span class="text-muted small mb-1">زیادکراوەی دەستی (کەم/زیاد):</span>
                                <div class="h4 fw-bold mb-1 <?php echo $manualAdjustment >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $manualAdjustment >= 0 ? '+' : ''; ?><?php echo number_format($manualAdjustment, 0); ?> دینار
                                </div>
                                <span class="text-muted" style="font-size: 0.75rem;">(جیاوازی دەستی)</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="rate-bento-tile rate-bento-highlight">
                                <span class="text-muted small mb-1">کۆی نرخی ئێستا:</span>
                                <div class="rate-val-main">
                                    1$ = <?php echo number_format($totalRate, 0); ?>
                                </div>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill mx-auto" style="font-size: 0.75rem;">
                                    دیناری عێراقی
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$currentRate): ?>
                        <div class="alert alert-info text-center py-2 mb-4 small">
                            <i class="bi bi-info-circle me-1"></i>
                            هیچ نرخێکی تایبەت تۆمار نەکراوە. دیفۆڵتی سیستەم: 1$ = 1,400 دینار
                        </div>
                    <?php endif; ?>
                    
                    <!-- Exchange Rate Form -->
                    <form method="POST" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <!-- Manual Adjustment Field -->
                        <div class="mb-4">
                            <label for="manual_adjustment" class="form-label fw-bold">
                                <i class="bi bi-plus-slash-minus"></i> زیادکراوەی دەستی (دینار)
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-body-tertiary">+/-</span>
                                <input type="number" 
                                       class="form-control" 
                                       id="manual_adjustment" 
                                       name="manual_adjustment" 
                                       value="<?php echo number_format($manualAdjustment, 0, '', ''); ?>"
                                       step="1"
                                       placeholder="0">
                                <span class="input-group-text bg-body-tertiary">دینار</span>
                            </div>
                            <div class="form-text mt-2">
                                نموونە: ئەگەر دەتەوێت هەمیشە ٥ دینار لەسەر نرخی بازاڕ دابنێت، بنووسە <code>5</code>.
                            </div>
                        </div>
                        
                        <div class="alert alert-warning mb-4 small">
                            <i class="bi bi-lightbulb-fill me-1"></i>
                            <strong>تێبینی گرنگ:</strong> کاتێک نرخی بنەڕەتی لە بازاڕدا بگۆڕێت، ئەم زیادکراوە دەستییە بەردەوام وەک خۆی زیاد دەکرێتە سەری.
                        </div>
                        
                        <!-- Full Rate Field (Alternative) -->
                        <div class="mb-4">
                            <label for="usd_to_iqd_rate" class="form-label fw-bold">
                                <i class="bi bi-cash-stack"></i> یان نرخی تەواو دیاری بکە (1$ = ? دینار)
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-body-tertiary">1$ =</span>
                                <input type="number" 
                                       class="form-control" 
                                       id="usd_to_iqd_rate" 
                                       name="usd_to_iqd_rate" 
                                       value="<?php echo number_format($totalRate, 0, '', ''); ?>"
                                       min="0.001" 
                                       step="0.001">
                                <span class="input-group-text bg-body-tertiary">دینار</span>
                            </div>
                            <div class="form-text mt-2">
                                کاتێک نرخی تەواو دەگۆڕیت، جیاوازییەکە بە شێوەی ئۆتۆماتیکی بۆ زیادکراوەی دەستی حساب دەکرێت.
                            </div>
                        </div>
                        
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 border-top border-light-subtle">
                            <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-right"></i> گەڕانەوە
                            </a>
                            <button type="submit" class="btn btn-save">
                                <i class="bi bi-check-lg"></i> پاشەکەوتکردنی ڕێژە
                            </button>
                        </div>
                    </form>
                    
                    <script>
                    // Auto-calculate manual adjustment when full rate changes
                    document.getElementById('usd_to_iqd_rate').addEventListener('input', function() {
                        var fullRate = parseFloat(this.value) || 0;
                        var baseRate = <?php echo $baseRate; ?>;
                        var manualAdjustment = fullRate - baseRate;
                        document.getElementById('manual_adjustment').value = Math.round(manualAdjustment);
                    });
                    
                    // Auto-calculate full rate when manual adjustment changes
                    document.getElementById('manual_adjustment').addEventListener('input', function() {
                        var manualAdjustment = parseFloat(this.value) || 0;
                        var baseRate = <?php echo $baseRate; ?>;
                        var fullRate = baseRate + manualAdjustment;
                        document.getElementById('usd_to_iqd_rate').value = Math.round(fullRate);
                    });
                    </script>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

