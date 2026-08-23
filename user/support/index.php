<?php
/**
 * پاڵپشتی - user/support/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'dashboard.view', [
    'route' => '/user/support/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>پاڵپشتی - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
</head>
<body>
    <div class="support-container">
        <!-- Header -->
        <div class="support-header">
            <div class="support-icon">
                <i class="bi bi-heart-fill"></i>
            </div>
            <h1>پاڵپشتی پڕۆژە</h1>
            <p class="subtitle">بەشداریکردن لە گەشەکردنی سیستەمی NexoraCore</p>
        </div>

        <!-- Support Balance Card -->
        <div class="content-card mb-4" id="balanceCard">
            <div class="text-center mb-4">
                <h3 style="color: var(--primary-color); font-weight: 600; font-size: 1.5rem;">
                    <i class="bi bi-wallet2"></i> بڕی پاڵپشتی ئێوە
                </h3>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="balance-card current-balance">
                        <div class="balance-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="balance-label">بڕی ئێستا</div>
                        <div class="balance-amount" id="currentBalance">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">چاوەڕوان بە...</span>
                            </div>
                        </div>
                        <div class="balance-currency">دینار</div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="balance-card total-added">
                        <div class="balance-icon">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                        <div class="balance-label">کۆی زیادکراو</div>
                        <div class="balance-amount" id="totalAdded">
                            <div class="spinner-border text-success" role="status">
                                <span class="visually-hidden">چاوەڕوان بە...</span>
                            </div>
                        </div>
                        <div class="balance-currency">دینار</div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="balance-card total-used">
                        <div class="balance-icon">
                            <i class="bi bi-arrow-down-circle"></i>
                        </div>
                        <div class="balance-label">کۆی بەکارهاتوو</div>
                        <div class="balance-amount" id="totalUsed">
                            <div class="spinner-border text-danger" role="status">
                                <span class="visually-hidden">چاوەڕوان بە...</span>
                            </div>
                        </div>
                        <div class="balance-currency">دینار</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- History Tabs -->
        <div class="content-card mb-4" id="historyCard">
            <ul class="nav nav-tabs mb-4" id="historyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="additions-tab" data-bs-toggle="tab" data-bs-target="#additions" type="button" role="tab">
                        <i class="bi bi-plus-circle"></i> مێژووی زیادکردن
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="usages-tab" data-bs-toggle="tab" data-bs-target="#usages" type="button" role="tab">
                        <i class="bi bi-dash-circle"></i> مێژووی بەکارهێنان
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="historyTabContent">
                <!-- Additions Tab -->
                <div class="tab-pane fade show active" id="additions" role="tabpanel">
                    <div id="additionsLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">چاوەڕوان بە...</span>
                        </div>
                        <p class="mt-3">بارکردنی مێژوو...</p>
                    </div>
                    <div id="additionsContent" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>بڕی پارە</th>
                                        <th>جۆری پارەدان</th>
                                        <th>تێبینی</th>
                                        <th>بەروار</th>
                                    </tr>
                                </thead>
                                <tbody id="additionsTableBody">
                                </tbody>
                            </table>
                        </div>
                        <div id="noAdditions" class="text-center py-5" style="display: none;">
                            <i class="bi bi-inbox" style="font-size: 4rem; color: #ccc;"></i>
                            <p class="text-muted mt-3">هیچ مێژوویەکی زیادکردن نییە</p>
                        </div>
                    </div>
                </div>
                
                <!-- Usages Tab -->
                <div class="tab-pane fade" id="usages" role="tabpanel">
                    <div id="usagesLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">چاوەڕوان بە...</span>
                        </div>
                        <p class="mt-3">بارکردنی مێژوو...</p>
                    </div>
                    <div id="usagesContent" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>بڕی پارە</th>
                                        <th>باسی خزمەتگوزاری</th>
                                        <th>تێبینی</th>
                                        <th>بەروار</th>
                                    </tr>
                                </thead>
                                <tbody id="usagesTableBody">
                                </tbody>
                            </table>
                        </div>
                        <div id="noUsages" class="text-center py-5" style="display: none;">
                            <i class="bi bi-inbox" style="font-size: 4rem; color: #ccc;"></i>
                            <p class="text-muted mt-3">هیچ مێژوویەکی بەکارهێنان نییە</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-card">
            <div class="content-text">
                <p>
                    <strong>ئێمە چەند گەنجێکین</strong> کار لەسەر سیستەمی NexoraCore دەکەین، تازە تەخەرووجمان کردوە. دەمانەوێت ئەم سیستەمە هێندە بەهێز و خێرا و باش بێت ببێتە <strong>یەکەم لە ناوچەکەدا</strong>.
                </p>

                <p>
                   جگە لەوەی <strong>هەوڵێکی زۆر و کات و پارەیەکی باش </strong> تەرخان دەکەین بۆ بەردەوام بەرەوپێش چوونی سیستەمەکە  
                </p>
                <p>
                    ڕەنگە هەندێ جار بەهۆی ئەوەی پارەی تەواومان لەبەردەست نەبێت، ئیشەکان نەدەن بەدەستەوە یان خاو بدەن بەدەستەوە.
                </p>

                <div class="highlight-box">
                    <h4><i class="bi bi-stars"></i> بۆچی پاڵپشتی بکەن؟</h4>
                    <p>
                        ئێوە دەتوانن بە بڕی مادی جیاواز پاڵپشتی پڕۆژەکە بکەن بۆ زووگەشەسەندن و بەرەوپێش چوونی زیاتر و زیاتر، کار ئاسانی و سودی زیاترتان پێ بگەیەنێت.
                    </p>
                </div>

             

                <div class="stats-grid">
                    <div class="stat-card">
                        <i class="bi bi-rocket-takeoff"></i>
                        <h4>گەشەی خێرا</h4>
                        <p>بەهێزکردنی سیستەمەکە بە خێرایی</p>
                    </div>
                    <div class="stat-card">
                        <i class="bi bi-shield-check"></i>
                        <h4>کوالیتی بەرز</h4>
                        <p>باشترین خزمەتگوزاری بۆ ئێوە</p>
                    </div>
                    <div class="stat-card">
                        <i class="bi bi-gift"></i>
                        <h4>سوودی زیاتر</h4>
                        <p>تایبەتمەندی نوێ بە بێ پارەدان</p>
                    </div>
                </div>

                <h4 class="mt-5 mb-4" style="color: var(--primary-color); font-weight: 600; font-size: 1.25rem;">
                    <i class="bi bi-check-circle-fill"></i> سوودەکانی پاڵپشتیکردن:
                </h4>

                <ul class="feature-list">
                    <li>
                        <i class="bi bi-trophy-fill"></i>
                        <span><strong>بەرزدەنرخێنرێت:</strong> پاڵپشتیەکانتان جگە لەوەی ئێمە بەرز دەینرخێنین، دەبێتە هۆکارێک بۆ بەهێز بوون و باشتر بوونی سیستەمەکە</span>
                    </li>
                    <li>
                        <i class="bi bi-bookmark-check-fill"></i>
                        <span><strong>تۆمارکردن:</strong> ئەو بڕە پاڵپشتیانەی ئێوە دەینێرن بۆتان تۆمار دەکرێت و لەم بەشە دەیبینەوە</span>
                    </li>
                    <li>
                        <i class="bi bi-percent"></i>
                        <span><strong>داشکاندن لە نرخ:</strong> هەر ڕۆژێ لە ڕۆژان خزمەتگوزاریە نوێ یان پاکێجێک هەبوو بە پارە، یەکەم جار نرخەکەی لە بڕی پاڵپشتی کراوتان کەم دەکرێتەوە</span>
                    </li>
                    <li>
                        <i class="bi bi-star-fill"></i>
                        <span><strong>سوودی داهاتوو:</strong> بۆ ئێستا و داهاتووش ان شاء الله زۆر زیاتر سودی دەبێت بۆتان</span>
                    </li>
                </ul>

                <div class="highlight-box mt-5">
                    <h4><i class="bi bi-lightbulb-fill"></i> نموونەیەک:</h4>
                    <p style="margin-bottom: 15px;">
                        تۆی بەڕێز ئێستا <strong>200 هەزار</strong> پاڵپشتیت دەنێریت، لەبەشی پاڵپشتی بۆتان تۆمار کراوە.
                    </p>
                    <p style="margin-bottom: 15px;">
                        ساڵی 2026 تایبەتمەندیەکی زیاد دەکرێت، نرخی <strong>50 هەزارە</strong>. تایبەتمەندیە نوێکە بۆ ئیش و کاری ئێوە زۆر باشە و قازانجی زۆر دەبێت، بۆیە دەتانەوێت چالاکی بکەن.
                    </p>
                    <p style="margin-bottom: 15px;">
                        دەڵێن بۆمان چالاک بکەن، لە بڕی پاڵپشتی دەری بکەن. ئێمەش 2026 خزمەتگوزاریەکەتان بۆ چالاک دەکەین و <strong>50 هەزار لە بڕی پاڵپشتی کەمی دەکەینەوە</strong>.
                    </p>
                    <p style="color: var(--primary-color); font-weight: 500; margin: 0;">
                        واتا ئێوە 2026 هیچ پارەیەک نادەن بۆ بەدەستهێنانی خزمەتگوزاریەکە، بەڵکوو سود لە پارەی پاڵپشتی کراو دەبینرێت! 🎉
                    </p>
                </div>
            </div>



        <div class="highlight-box support-payment-section">
                    <h4 class="support-payment-heading"><i class="bi bi-credit-card-2-front"></i> ڕێگاکانی پاڵپشتیکردن</h4>
                    <p class="support-payment-note" style="font-size: 1rem; margin-bottom: 20px;">
                        دەتوانن پاڵپشتیەکانتان لەڕێگای ئەم شێوازانەی خوارەوە پێشکەش بکەن:
                    </p>
                    <div class="row g-3 mt-3">
                        <div class="col-md-6 col-lg-3">
                            <div class="payment-method-card" style="border-color: #10b981;">
                                <h5 style="color: #059669; font-weight: 600; margin: 0; font-size: 1rem;">کاش</h5>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="payment-method-card" style="border-color: #d946ef;">
                                <h5 style="color: #c026d3; font-weight: 600; margin: 0; font-size: 1rem;">Fastpay</h5>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="payment-method-card" style="border-color: #06b6d4;">
                                <h5 style="color: #0891b2; font-weight: 600; margin: 0; font-size: 1rem;">FIB</h5>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="payment-method-card" style="border-color: #f59e0b;">
                                <h5 style="color: #d97706; font-weight: 600; margin: 0; font-size: 1rem;">Qi Card</h5>
                            </div>
                        </div>
                    </div>
                    <p style="margin-top: 20px; margin-bottom: 15px; font-size: 0.875rem; color: var(--text-secondary);">
                        <i class="bi bi-info-circle-fill" style="color: #10b981;"></i>
                        بۆ زانیاری زیاتر دەربارەی ڕێگاکانی پاڵپشتیکردن،  پەیوەندیمان پێوە بکەن.
                    </p>
                    <div class="support-payment-tip">
                        <div style="display: flex; align-items: flex-start; gap: 12px;">
                            <i class="bi bi-star-fill support-payment-tip-icon"></i>
                            <div>
                                <p class="support-payment-tip-text" style="margin: 0;">
                                    <strong>ئێستا دەتوانن بە هەر بڕێک بێت پاڵپشتی بکەن</strong> لە <span class="support-payment-tip-amount">250 دینار</span> وە تا <span class="support-payment-tip-amount">250 هەزار</span> یان زیاتر بەپێی توانا و گونجان بۆتان دەتوانن پاڵپشتیەکانتان بنێرن. هەرچەند بێت ئێمە بەرز دەینرخێنین. ✨
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                
            <!-- CTA Section -->
            <div class="cta-section">
                <h3>ئامادەن پاڵپشتیمان بکەن؟</h3>
                <p style="font-size: 1.1rem; margin-bottom: 30px; opacity: 0.95;">
                    بۆ زانیاری زیاتر و پاڵپشتیکردن، پەیوەندیمان پێوە بکەن
                </p>
                <a href="https://t.me/itz_levi0" target="_blank" class="btn-support">
                    <i class="bi bi-telegram"></i>
                    پەیوەندی لە ڕێگەی تیلیگرام
                </a>
            </div>
        </div>

        <!-- Back Button -->
        <div class="text-center">
            <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn-back">
                <i class="bi bi-arrow-right"></i>
                گەڕانەوە بۆ داشبۆرد
            </a>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // بارکردنی بڕی پاڵپشتی
        async function loadBalance() {
            try {
                const response = await fetch('<?php echo url('user/support/api/get_balance.php'); ?>');
                const result = await response.json();
                
                if (result.success) {
                    const balance = result.data.balance;
                    
                    // نیشاندانی بڕەکان
                    document.getElementById('currentBalance').innerHTML = balance.current;
                    document.getElementById('totalAdded').innerHTML = balance.total_added;
                    document.getElementById('totalUsed').innerHTML = balance.total_used;
                } else {
                    throw new Error(result.message || 'هەڵەیەک ڕوویدا');
                }
            } catch (error) {
                console.error('Error loading balance:', error);
                document.getElementById('currentBalance').innerHTML = '<span style="font-size: 1rem;">هەڵە لە بارکردن</span>';
                document.getElementById('totalAdded').innerHTML = '<span style="font-size: 1rem;">هەڵە لە بارکردن</span>';
                document.getElementById('totalUsed').innerHTML = '<span style="font-size: 1rem;">هەڵە لە بارکردن</span>';
            }
        }
        
        // بارکردنی مێژوو
        async function loadHistory() {
            try {
                const response = await fetch('<?php echo url('user/support/api/get_history.php'); ?>');
                const result = await response.json();
                
                if (result.success) {
                    const { additions, usages } = result.data;
                    
                    // پڕکردنەوەی خشتەی زیادکردن
                    const additionsTableBody = document.getElementById('additionsTableBody');
                    if (additions.length > 0) {
                        additionsTableBody.innerHTML = additions.map((item, index) => `
                            <tr>
                                <td>${index + 1}</td>
                                <td><strong>${item.amount}</strong> دینار</td>
                                <td><span class="badge-payment badge-${getPaymentTypeBadgeClass(item.payment_type)}">${item.payment_type_label}</span></td>
                                <td>${item.notes || '-'}</td>
                                <td><i class="bi bi-calendar-event"></i> ${item.created_at_formatted}</td>
                            </tr>
                        `).join('');
                        document.getElementById('additionsContent').style.display = 'block';
                    } else {
                        document.getElementById('noAdditions').style.display = 'block';
                        document.getElementById('additionsContent').style.display = 'block';
                    }
                    document.getElementById('additionsLoading').style.display = 'none';
                    
                    // پڕکردنەوەی خشتەی بەکارهێنان
                    const usagesTableBody = document.getElementById('usagesTableBody');
                    if (usages.length > 0) {
                        usagesTableBody.innerHTML = usages.map((item, index) => `
                            <tr>
                                <td>${index + 1}</td>
                                <td><strong>${item.amount}</strong> دینار</td>
                                <td>${item.service_description}</td>
                                <td>${item.notes || '-'}</td>
                                <td><i class="bi bi-calendar-event"></i> ${item.created_at_formatted}</td>
                            </tr>
                        `).join('');
                        document.getElementById('usagesContent').style.display = 'block';
                    } else {
                        document.getElementById('noUsages').style.display = 'block';
                        document.getElementById('usagesContent').style.display = 'block';
                    }
                    document.getElementById('usagesLoading').style.display = 'none';
                } else {
                    throw new Error(result.message || 'هەڵەیەک ڕوویدا');
                }
            } catch (error) {
                console.error('Error loading history:', error);
                document.getElementById('additionsLoading').innerHTML = '<div class="alert alert-danger">هەڵە لە بارکردنی مێژوو</div>';
                document.getElementById('usagesLoading').innerHTML = '<div class="alert alert-danger">هەڵە لە بارکردنی مێژوو</div>';
            }
        }
        
        // وەرگرتنی کلاسی badge بەپێی جۆری پارەدان
        function getPaymentTypeBadgeClass(type) {
            const classes = {
                'cash': 'cash',
                'fastpay': 'fastpay',
                'fib': 'fib',
                'qi_card': 'qi'
            };
            return classes[type] || 'cash';
        }
        
        // بارکردنی داتاکان لە کاتی بارکردنی پەیج
        document.addEventListener('DOMContentLoaded', function() {
            loadBalance();
            loadHistory();
        });
    </script>
</body>
</html>

