<?php
/**
 * مێژووی قەرز و پارەدانەکان - user/debts/history.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// فیلتەرەکان
$debtId = (int)($_GET['debt_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$dateFrom = cleanInput($_GET['date_from'] ?? '');
$dateTo = cleanInput($_GET['date_to'] ?? '');
$search = cleanInput($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;

// دروستکردنی کلۆزی WHERE
$whereConditions = ["dp.debt_id IN (SELECT id FROM debts WHERE user_id = ?)"];
$params = [$userId];
$types = 'i';

if ($debtId > 0) {
    $whereConditions[] = "dp.debt_id = ?";
    $params[] = $debtId;
    $types .= 'i';
}

if ($customerId > 0) {
    $whereConditions[] = "d.customer_id = ?";
    $params[] = $customerId;
    $types .= 'i';
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(dp.payment_date) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(dp.payment_date) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

if (!empty($search)) {
    $whereConditions[] = "(d.customer_name LIKE ? OR dr.receipt_number LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm]);
    $types .= 'ss';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// ژماردنی گشتی پارەدانەکان
$countQuery = "
    SELECT COUNT(*) as total 
    FROM debt_payments dp
    JOIN debts d ON dp.debt_id = d.id
    LEFT JOIN debt_receipts dr ON dp.id = dr.debt_payment_id
    $whereClause
";
$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalPayments = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalPayments / $limit);

// وەرگرتنی مێژووی پارەدانەکان
$query = "
    SELECT dp.*, 
           d.customer_name,
           d.customer_phone,
           d.total_debt,
           c.name as customer_name_updated,
           c.phone as customer_phone_updated,
           dr.receipt_number,
           dr.payment_method,
           COALESCE(s.currency, 'IQD') as currency,
           DATE_FORMAT(dp.payment_date, '%Y/%m/%d %H:%i') as formatted_date,
           DATE_FORMAT(dp.payment_date, '%d/%m/%Y') as short_date,
           DATE_FORMAT(dp.payment_date, '%H:%i') as time
    FROM debt_payments dp
    JOIN debts d ON dp.debt_id = d.id
    LEFT JOIN customers c ON d.customer_id = c.id
    LEFT JOIN debt_receipts dr ON dp.id = dr.debt_payment_id
    LEFT JOIN sales s ON d.sale_id = s.id
    $whereClause
    ORDER BY dp.payment_date DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ئامارەکان
$statsQuery = "
    SELECT 
        COUNT(*) as total_payments,
        COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN dp.payment_amount ELSE 0 END), 0) as total_amount_iqd,
        COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN dp.payment_amount ELSE 0 END), 0) as total_amount_usd,
        COALESCE(AVG(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN dp.payment_amount ELSE NULL END), 0) as average_payment_iqd,
        COALESCE(AVG(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN dp.payment_amount ELSE NULL END), 0) as average_payment_usd
    FROM debt_payments dp
    JOIN debts d ON dp.debt_id = d.id
    LEFT JOIN sales s ON d.sale_id = s.id
    WHERE d.user_id = ?
";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bind_param("i", $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// وەرگرتنی کڕیاران بۆ فیلتەر
$customersQuery = "SELECT id, name, phone FROM customers WHERE user_id = ? ORDER BY name";
$customersStmt = $conn->prepare($customersQuery);
$customersStmt->bind_param("i", $userId);
$customersStmt->execute();
$customers = $customersStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Helper function بۆ شێوەکردنی بڕ بەپێی دراو
function formatAmount($amount, $currency = 'IQD') {
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
    return number_format((float)$amount, 0) . ' دینار';
}

$pageTitle = "مێژووی پارەدانەکان";
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-pages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-responsive.css'); ?>" rel="stylesheet">
    <style>
        html[data-bs-theme='dark'] body {
            background-color: #0f172a !important;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] .card,
        html[data-bs-theme='dark'] .card-header,
        html[data-bs-theme='dark'] .card-body,
        html[data-bs-theme='dark'] .table,
        html[data-bs-theme='dark'] .table td,
        html[data-bs-theme='dark'] .modal-content {
            background-color: #111827 !important;
            border-color: #374151 !important;
            color: #e5e7eb !important;
        }

        html[data-bs-theme='dark'] .table-light,
        html[data-bs-theme='dark'] .table-light th {
            background-color: #1f2937 !important;
            color: #d1d5db !important;
            border-color: #374151 !important;
        }

        .customer-filter-card {
            position: relative;
            z-index: 30;
            overflow: visible;
        }

        .customer-filter-card .card-body {
            overflow: visible;
        }

        .customer-combobox {
            position: relative;
        }

        .customer-combobox .customer-search-input {
            border-radius: 0.6rem;
            padding-right: 2.25rem;
        }

        .customer-combobox .customer-search-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            color: var(--bs-secondary-color);
            pointer-events: none;
            z-index: 2;
        }

        .customer-combobox .customer-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            z-index: 1080;
            border: 1px solid var(--bs-border-color);
            border-radius: 0.75rem;
            background: var(--bs-body-bg);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            max-height: 280px;
            overflow-y: auto;
            display: none;
        }

        .customer-combobox .customer-dropdown.show {
            display: block;
        }

        .customer-combobox .customer-option {
            cursor: pointer;
            padding: 0.6rem 0.8rem;
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }

        .customer-combobox .customer-option:last-child {
            border-bottom: 0;
        }

        .customer-combobox .customer-option.active,
        .customer-combobox .customer-option:hover {
            background: var(--bs-primary-bg-subtle);
        }

        .customer-combobox .customer-option .name {
            font-weight: 600;
            display: block;
        }

        .customer-combobox .customer-option .meta {
            font-size: 0.83rem;
            color: var(--bs-secondary-color);
        }
    </style>
</head>
<body class="customers-module-page debts-history-page">

    <?php
    $customersNavId = 'debtsHistoryNav';
    $customersNavLinks = [
        ['href' => url('user/customers/index.php'), 'icon' => 'bi-people', 'text' => 'لیستی کڕیاران'],
        ['href' => url('user/customers/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کڕیاران'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
    include dirname(__DIR__) . '/customers/partials/customers_nav.php';
    ?>

    <div class="container-fluid py-4 customers-page-content cu-wrap">
        
        <header class="cu-hero">
            <div>
                <div class="cu-kicker"><i class="bi bi-clock-history"></i> قەرز و پارەدان</div>
                <h1><i class="bi bi-receipt"></i> <?php echo $pageTitle; ?></h1>
                <p class="cu-hero-sub">هەموو پارەدانەوەکانی قەرز بە کڕیار، بەروار و ژمارەی وەسڵ بگەڕێ</p>
                <div class="cu-hero-pills">
                    <span class="cu-pill"><i class="bi bi-collection"></i> <?php echo number_format($stats['total_payments']); ?> پارەدان</span>
                </div>
            </div>
            <div class="cu-actions">
                <a class="cu-btn cu-btn-ghost" href="<?php echo url('user/customers/index.php'); ?>">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </header>

        <div class="cu-stats cu-stats-3">
            <div class="cu-stat" style="--stat-accent:#06b6d4">
                <div class="cu-stat-icon"><i class="bi bi-list-ul"></i></div>
                <div>
                    <div class="cu-stat-label">کۆی پارەدانەکان</div>
                    <div class="cu-stat-value"><?php echo number_format($stats['total_payments']); ?></div>
                </div>
            </div>
            <div class="cu-stat" style="--stat-accent:#10b981">
                <div class="cu-stat-icon"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="cu-stat-label">کۆی بڕی پارە</div>
                    <div class="cu-stat-value">
                        <?php 
                        $totalIQD = $stats['total_amount_iqd'] ?? 0;
                        $totalUSD = $stats['total_amount_usd'] ?? 0;
                        if ($totalUSD > 0 && $totalIQD > 0) {
                            echo formatAmount($totalIQD, 'IQD') . ' + ' . formatAmount($totalUSD, 'USD');
                        } elseif ($totalUSD > 0) {
                            echo formatAmount($totalUSD, 'USD');
                        } else {
                            echo formatAmount($totalIQD, 'IQD');
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="cu-stat" style="--stat-accent:#f59e0b">
                <div class="cu-stat-icon"><i class="bi bi-calculator"></i></div>
                <div>
                    <div class="cu-stat-label">ناوەندی پارەدان</div>
                    <div class="cu-stat-value">
                        <?php 
                        $avgIQD = $stats['average_payment_iqd'] ?? 0;
                        $avgUSD = $stats['average_payment_usd'] ?? 0;
                        if ($avgUSD > 0 && $avgIQD > 0) {
                            echo formatAmount($avgIQD, 'IQD') . ' / ' . formatAmount($avgUSD, 'USD');
                        } elseif ($avgUSD > 0) {
                            echo formatAmount($avgUSD, 'USD');
                        } else {
                            echo formatAmount($avgIQD, 'IQD');
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="cu-panel customer-filter-card">
            <div class="cu-panel-head"><i class="bi bi-funnel"></i> فیلتەرەکان</div>
            <div class="cu-panel-body">
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">کڕیار</label>
                            <div class="customer-combobox">
                                <i class="bi bi-search customer-search-icon"></i>
                                <input type="text"
                                       id="customerSearchInput"
                                       class="form-control customer-search-input"
                                       placeholder="بە ناو یان تەلەفۆن گەڕان بکە..."
                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                                <div id="customerDropdown" class="customer-dropdown" role="listbox" aria-label="لیستی کڕیاران"></div>
                            </div>
                            <select class="form-select d-none" id="customer_id" name="customer_id">
                                <option value="">هەموو کڕیارەکان</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>"
                                            data-name="<?php echo htmlspecialchars((string)($customer['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-phone="<?php echo htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $customerId == $customer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                        <?php if (!empty($customer['phone'])): ?>
                                            - <?php echo htmlspecialchars($customer['phone']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">لە بەروار</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $dateFrom; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تا بەروار</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $dateTo; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">گەڕان</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="ژمارەی وەسڵ"
                                   autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="cu-btn cu-btn-primary me-2">
                                <i class="bi bi-search"></i> گەڕان
                            </button>
                            <a href="<?php echo url('user/debts/history.php'); ?>" class="cu-btn cu-btn-ghost">
                                <i class="bi bi-arrow-clockwise"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="cu-panel">
            <div class="cu-panel-head"><i class="bi bi-table"></i> مێژووی پارەدانەکان</div>
            <div class="p-0">
                <?php if (empty($payments)): ?>
                    <div class="cu-empty">
                        <div class="cu-empty-icon"><i class="bi bi-receipt"></i></div>
                        <h3>هیچ پارەدانێک نەدۆزرایەوە</h3>
                        <p>فلتەرەکان بگۆڕە یان کڕیارێکی تر هەڵبژێرە.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>کڕیار</th>
                                    <th>بڕی پارەدان</th>
                                    <th>شێوەی پارەدان</th>
                                    <th>ژمارەی وەسڵ</th>
                                    <th>بەروار</th>
                                    <th>تێبینی</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $index => $payment): ?>
                                    <tr>
                                        <td data-label="#"><?php echo $offset + $index + 1; ?></td>
                                        <td data-label="کڕیار">
                                            <strong><?php echo htmlspecialchars($payment['customer_name_updated'] ?: $payment['customer_name']); ?></strong>
                                            <?php if ($payment['customer_phone_updated'] || $payment['customer_phone']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payment['customer_phone_updated'] ?: $payment['customer_phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="بڕی پارەدان">
                                            <span class="badge bg-success fs-6">
                                                <?php echo formatAmount($payment['payment_amount'], $payment['currency'] ?? 'IQD'); ?>
                                            </span>
                                        </td>
                                        <td data-label="شێوەی پارەدان">
                                            <?php if ($payment['payment_method']): ?>
                                                <span class="badge bg-info">
                                                    <?php 
                                                    echo $payment['payment_method'] === 'cash' ? 'کاش' : 
                                                         ($payment['payment_method'] === 'bank_transfer' ? 'گواستنەوەی بانکی' : 'چەک'); 
                                                    ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="ژمارەی وەسڵ">
                                            <?php if ($payment['receipt_number']): ?>
                                                <code><?php echo $payment['receipt_number']; ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="بەروار">
                                            <div><?php echo $payment['short_date']; ?></div>
                                            <small class="text-muted"><?php echo $payment['time']; ?></small>
                                        </td>
                                        <td data-label="تێبینی">
                                            <?php if ($payment['notes']): ?>
                                                <span class="text-truncate d-inline-block" style="max-width: 150px;" 
                                                      title="<?php echo htmlspecialchars($payment['notes']); ?>">
                                                    <?php echo htmlspecialchars($payment['notes']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($payment['receipt_number']): ?>
                                                    <a href="<?php echo url('user/receipts/print.php?receipt_number=' . urlencode($payment['receipt_number']) . '&print=1'); ?>" 
                                                       class="btn btn-outline-primary" target="_blank" title="چاپکردن">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger" 
                                                        onclick="deletePayment(<?php echo $payment['id']; ?>, '<?php echo htmlspecialchars($payment['customer_name_updated'] ?: $payment['customer_name']); ?>', <?php echo $payment['payment_amount']; ?>, '<?php echo $payment['currency'] ?? 'IQD'; ?>')"
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

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php
                    $queryString = http_build_query(array_filter([
                        'customer_id' => $customerId ?: null,
                        'date_from' => $dateFrom ?: null,
                        'date_to' => $dateTo ?: null,
                        'search' => $search ?: null
                    ]));
                    $baseUrl = url('user/debts/history.php') . ($queryString ? '?' . $queryString . '&' : '?');
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>">پێشوو</a>
                        </li>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>">دواتر</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function deletePayment(paymentId, customerName, amount, currency) {
            // شێوەکردنی بڕ بەپێی دراو
            const currencySymbol = (currency === 'USD') ? '$' : ' دینار';
            const decimals = (currency === 'USD') ? 2 : 0;
            const formattedAmount = parseFloat(amount).toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }) + currencySymbol;
            
            Swal.fire({
                title: 'دڵنیای لە سڕینەوە؟',
                html: `
                    <div class="text-end" dir="rtl">
                        <p><strong>کڕیار:</strong> ${customerName}</p>
                        <p><strong>بڕی پارە:</strong> ${formattedAmount}</p>
                        <p class="text-danger mt-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            ئەم پارەدانە دەسڕێتەوە و قەرزەکە دەگەڕێتەوە بۆ دۆخی پێشوو
                        </p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'بەڵێ، بیسڕەوە',
                cancelButtonText: 'پاشگەزبوونەوە',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // نیشاندانی لۆدینگ
                    Swal.fire({
                        title: 'تکایە چاوەڕێ بکە...',
                        text: 'پارەدانەکە دەسڕێتەوە',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // ناردنی داواکاری سڕینەوە
                    const formData = new FormData();
                    formData.append('payment_id', paymentId);

                    fetch('<?php echo url('user/debts/delete_payment.php'); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'سەرکەوتوو بوو!',
                                text: data.message,
                                confirmButtonText: 'باشە'
                            }).then(() => {
                                // نوێکردنەوەی لاپەڕە
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'هەڵە!',
                                text: data.message,
                                confirmButtonText: 'باشە'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'هەڵە!',
                            text: 'هەڵەیەک ڕوویدا لە پەیوەندی کردن',
                            confirmButtonText: 'باشە'
                        });
                        console.error('Error:', error);
                    });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const customerSearchInput = document.getElementById('customerSearchInput');
            const customerDropdown = document.getElementById('customerDropdown');
            const customerSelect = document.getElementById('customer_id');

            if (!customerSearchInput || !customerDropdown || !customerSelect) {
                return;
            }

            const allCustomerOption = customerSelect.querySelector('option[value=""]');
            const customerOptions = Array.from(customerSelect.options)
                .filter(option => option.value !== '')
                .map(option => ({
                    id: option.value,
                    name: option.dataset.name || '',
                    phone: option.dataset.phone || '',
                    label: option.textContent.trim()
                }));

            let activeIndex = -1;
            let visibleCustomers = [...customerOptions];
            let currentQuery = '';

            function normalize(text) {
                return (text || '').toLowerCase().trim();
            }

            function escapeHtml(value) {
                return (value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function renderDropdown() {
                const noResultHtml = '<div class="customer-option"><span class="meta">هیچ کڕیارێک نەدۆزرایەوە</span></div>';
                const allOptionHtml = `
                    <div class="customer-option ${customerSelect.value === '' ? 'active' : ''}" data-id="" role="option">
                        <span class="name">${escapeHtml(allCustomerOption ? allCustomerOption.textContent.trim() : 'هەموو کڕیارەکان')}</span>
                        <span class="meta">بۆ نیشاندانی هەموو ئەنجامەکان</span>
                    </div>
                `;

                const customersHtml = visibleCustomers.map((customer, index) => `
                    <div class="customer-option ${index === activeIndex ? 'active' : ''}" data-id="${customer.id}" role="option">
                        <span class="name">${escapeHtml(customer.name || 'بێ ناو')}</span>
                        <span class="meta">${escapeHtml(customer.phone || '')}</span>
                    </div>
                `).join('');

                const shouldShowAllOption = currentQuery === '';
                customerDropdown.innerHTML = (shouldShowAllOption ? allOptionHtml : '') + (customersHtml || noResultHtml);
            }

            function openDropdown() {
                customerDropdown.classList.add('show');
                renderDropdown();
            }

            function closeDropdown() {
                customerDropdown.classList.remove('show');
                activeIndex = -1;
            }

            function setSelectedCustomer(customerId, fromUserInput = false) {
                customerSelect.value = customerId;
                if (customerId === '') {
                    customerSearchInput.value = fromUserInput ? '' : (allCustomerOption ? allCustomerOption.textContent.trim() : '');
                    return;
                }

                const selected = customerOptions.find(customer => customer.id === customerId);
                if (selected) {
                    customerSearchInput.value = selected.name + (selected.phone ? ` - ${selected.phone}` : '');
                }
            }

            function filterCustomers() {
                const query = normalize(customerSearchInput.value);
                currentQuery = query;
                visibleCustomers = customerOptions.filter(customer =>
                    normalize(customer.name).includes(query) || normalize(customer.phone).includes(query)
                );
                activeIndex = visibleCustomers.length ? 0 : -1;
                openDropdown();
            }

            customerSearchInput.addEventListener('focus', function() {
                currentQuery = normalize(customerSearchInput.value);
                visibleCustomers = [...customerOptions];
                activeIndex = -1;
                openDropdown();
            });

            customerSearchInput.addEventListener('input', function() {
                const query = normalize(customerSearchInput.value);
                currentQuery = query;
                if (query === '') {
                    setSelectedCustomer('', true);
                    visibleCustomers = [...customerOptions];
                    activeIndex = -1;
                    openDropdown();
                    return;
                }
                filterCustomers();
            });

            customerSearchInput.addEventListener('keydown', function(event) {
                if (!customerDropdown.classList.contains('show')) return;

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (visibleCustomers.length > 0) {
                        activeIndex = (activeIndex + 1) % visibleCustomers.length;
                        renderDropdown();
                    }
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (visibleCustomers.length > 0) {
                        activeIndex = activeIndex <= 0 ? visibleCustomers.length - 1 : activeIndex - 1;
                        renderDropdown();
                    }
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    if (activeIndex >= 0 && visibleCustomers[activeIndex]) {
                        setSelectedCustomer(visibleCustomers[activeIndex].id);
                    }
                    closeDropdown();
                } else if (event.key === 'Escape') {
                    closeDropdown();
                }
            });

            customerDropdown.addEventListener('mousedown', function(event) {
                const optionElement = event.target.closest('.customer-option');
                if (!optionElement) return;

                const optionId = optionElement.dataset.id ?? '';
                setSelectedCustomer(optionId, optionId === '');
                closeDropdown();
            });

            document.addEventListener('click', function(event) {
                if (!event.target.closest('.customer-combobox')) {
                    closeDropdown();
                }
            });

            if (customerSelect.value) {
                setSelectedCustomer(customerSelect.value);
            } else {
                customerSearchInput.value = '';
            }
        });
    </script>

</body>
</html>