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

    
    <style>
        .exchange-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .exchange-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        
        .currency-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        .rate-display {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
        }
        
        .rate-value {
            font-size: 3rem;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-save {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 12px 40px;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .exchange-page {
            background: var(--bs-body-bg);
        }

        body.dark-mode .exchange-card,
        [data-bs-theme="dark"] .exchange-card,
        [data-theme="dark"] .exchange-card {
            background: #1f2937;
            color: #e5e7eb;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
        }

        body.dark-mode .rate-display,
        [data-bs-theme="dark"] .rate-display,
        [data-theme="dark"] .rate-display {
            background: linear-gradient(135deg, #111827, #1f2937);
            border: 1px solid #374151;
        }

        body.dark-mode .rate-value,
        [data-bs-theme="dark"] .rate-value,
        [data-theme="dark"] .rate-value {
            color: #93c5fd;
        }

        body.dark-mode .input-group-text,
        [data-bs-theme="dark"] .input-group-text,
        [data-theme="dark"] .input-group-text {
            background: #111827;
            color: #e5e7eb;
            border-color: #374151;
        }
    </style>
</head>
<body class="settings-module-page exchange-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-currency-exchange"></i>
                            ڕێژەی ئاڵوگۆڕکردنی دراوە
                        </h2>
                        <p class="text-muted mb-0">دانانی ڕێژەی ئاڵوگۆڕکردنی دۆلار و دینار</p>
                    </div>
                    <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە
                    </a>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Exchange Rate Card -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card exchange-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <div class="currency-icon">
                                <i class="bi bi-currency-exchange"></i>
                            </div>
                            <h4 class="mb-3">ڕێژەی ئاڵوگۆڕکردن</h4>
                            <p class="text-muted">دۆلار بۆ دینار</p>
                        </div>
                        
                        <!-- Rate Information Display -->
                        <div class="rate-display mb-4">
                            <div class="row text-center">
                                <div class="col-md-4 mb-3">
                                    <div class="text-muted mb-2">نرخی ئۆتۆماتیکی:</div>
                                    <div class="h4 text-primary">
                                        <?php echo number_format($baseRate, 0); ?> دینار
                                    </div>
                                    <small class="text-muted">(لە dollar_prices)</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="text-muted mb-2">زیادکراوەی دەستی:</div>
                                    <div class="h4 <?php echo $manualAdjustment >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $manualAdjustment >= 0 ? '+' : ''; ?><?php echo number_format($manualAdjustment, 0); ?> دینار
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="text-muted mb-2">کۆی گشتی:</div>
                                    <div class="rate-value" style="font-size: 2rem;">
                                        1$ = <?php echo number_format($totalRate, 0); ?> دینار
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!$currentRate): ?>
                            <div class="alert alert-info text-center">
                                <i class="bi bi-info-circle"></i>
                                هیچ ڕێژەیەک تۆمار نەکراوە. بە default: 1$ = 1,400 دینار
                            </div>
                        <?php endif; ?>
                        
                        <!-- Exchange Rate Form -->
                        <form method="POST" class="mt-4">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <!-- Manual Adjustment Field -->
                            <div class="mb-4">
                                <label for="manual_adjustment" class="form-label">
                                    <i class="bi bi-plus-circle"></i> زیادکراوەی دەستی (دینار)
                                </label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">+/-</span>
                                    <input type="number" 
                                           class="form-control" 
                                           id="manual_adjustment" 
                                           name="manual_adjustment" 
                                           value="<?php echo number_format($manualAdjustment, 0, '', ''); ?>"
                                           step="1"
                                           placeholder="0">
                                    <span class="input-group-text">دینار</span>
                                </div>
                                <div class="form-text">
                                    بۆ نمونە: ئەگەر 5 دینار زیاد بکەیت، تەنها 5 بنووسە. کاتێک نرخی بنەڕەتی بگۆڕێت، ئەم زیادکراوەیە بەردەوام دەمێنێتەوە.
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mb-4">
                                <i class="bi bi-info-circle"></i>
                                <strong>تێبینی:</strong> نرخی بنەڕەتی بە شێوەی ئۆتۆماتیکی لە dollar_prices وەردەگیرێت (<?php echo number_format($baseRate, 0); ?> دینار). 
                                کاتێک نرخی بنەڕەتی بگۆڕێت، زیادکراوەی دەستی بەردەوام دەمێنێتەوە.
                            </div>
                            
                            <!-- Full Rate Field (Alternative) -->
                            <div class="mb-4">
                                <label for="usd_to_iqd_rate" class="form-label">
                                    <i class="bi bi-currency-dollar"></i> یان نرخی تەواو دایبنێت (1$ = ? دینار)
                                </label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">1$ =</span>
                                    <input type="number" 
                                           class="form-control" 
                                           id="usd_to_iqd_rate" 
                                           name="usd_to_iqd_rate" 
                                           value="<?php echo number_format($totalRate, 0, '', ''); ?>"
                                           min="0.001" 
                                           step="0.001">
                                    <span class="input-group-text">دینار</span>
                                </div>
                                <div class="form-text">
                                    یان دەتوانیت نرخی تەواو دایبنیت. زیادکراوەکە بە شێوەی ئۆتۆماتیکی حساب دەکرێت.
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                                </a>
                                <button type="submit" class="btn btn-save">
                                    <i class="bi bi-save"></i> پاشەکەوتکردن
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
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

