<?php
/**
 * بەڕێوبردنی کۆمپانیاکان - user/companies/index.php
 */
 
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/permissions.php';
require_once '../../includes/company_computed_debt.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'companies.view', [
    'route' => '/user/companies/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

// پرۆسێسی سڕینەوە
if (isset($_POST['delete']) && isset($_POST['company_id'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setMessage('نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە', 'error');
        redirect(url('user/companies/index.php'));
    }

    $companyId = (int)$_POST['company_id'];
    
    // پشکنین کە کۆمپانیاکە بۆ یوزەرەکەیە
    $stmt = $conn->prepare("SELECT id FROM companies WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $companyId, $userId);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        // سڕینەوەی کۆمپانیا
        $deleteStmt = $conn->prepare("DELETE FROM companies WHERE id = ? AND user_id = ?");
        $deleteStmt->bind_param("ii", $companyId, $userId);
        
        if ($deleteStmt->execute()) {
            setMessage('کۆمپانیا بەسەرکەوتوویی سڕایەوە', 'success');
        } else {
            setMessage('هەڵە لە سڕینەوەی کۆمپانیا', 'error');
        }
    }
    
    redirect(url('user/companies/index.php'));
}

// گەڕان و فیلتەرکردن
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Query بنیادی
$whereConditions = ["c.user_id = ?"];
$params = [$userId];
$types = 'i';

// فیلتەری گەڕان
if (!empty($search)) {
    $whereConditions[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'sss';
}

// فیلتەری دۆخ
if (!empty($statusFilter)) {
    $whereConditions[] = "c.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

$computedDebtExpr = company_computed_remaining_debt_expr_sql('c');
$computedDebtExprUsd = company_computed_remaining_debt_expr_sql('c', 'USD');
$companyRemainingSub = company_remaining_by_id_subquery_sql();
$companyRemainingSubUsd = company_remaining_by_id_subquery_sql('USD');

// ژمارەی گشتی کۆمپانیاکان
$countQuery = "SELECT COUNT(*) as total FROM companies c $whereClause";
$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];

// وەرگرتنی کۆمپانیاکان
$query = "SELECT c.*, 
          (SELECT COUNT(*) FROM company_debts cd WHERE cd.company_id = c.id AND cd.type = 'debt') as debt_count,
          (SELECT COUNT(*) FROM company_debts cd WHERE cd.company_id = c.id AND cd.type = 'payment') as payment_count,
          (SELECT COUNT(*) FROM purchase_receipts pr WHERE pr.company_id = c.id AND pr.payment_type = 'debt' AND pr.status = 'active') as installment_count,
          $computedDebtExpr AS computed_remaining_debt,
          $computedDebtExprUsd AS computed_remaining_debt_usd
          FROM companies c
          $whereClause 
          ORDER BY c.name ASC 
          LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ئامارەکان (هەمان منطقی computed_remaining_debt)
$statsQuery = "SELECT
    COUNT(*) as total_companies,
    SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active_companies,
    SUM(CASE WHEN COALESCE(dr.remaining, 0) > 0 OR COALESCE(dr_usd.remaining, 0) > 0 THEN 1 ELSE 0 END) as companies_with_debt,
    SUM(COALESCE(dr.remaining, 0)) as total_debt,
    SUM(COALESCE(dr_usd.remaining, 0)) as total_debt_usd
    FROM companies c
    LEFT JOIN $companyRemainingSub dr ON dr.id = c.id
    LEFT JOIN $companyRemainingSubUsd dr_usd ON dr_usd.id = c.id
    WHERE c.user_id = ?";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bind_param("iii", $userId, $userId, $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$totalPages = (int)ceil($totalRecords / $perPage);
$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوبردنی کۆمپانیاکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/theme-modern.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/settings/settings.css'); ?>" rel="stylesheet">

    <style>
        .company-metric-card {
            background: var(--surface-1);
            border: 1px solid var(--border-default);
            border-radius: 18px;
            padding: 1.35rem;
            position: relative;
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
            height: 100%;
        }
        .company-metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
            border-color: rgba(79, 70, 229, 0.3);
        }
        .company-metric-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .icon-blue {
            background: rgba(59, 130, 246, 0.12);
            color: #3b82f6;
        }
        .icon-green {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }
        .icon-amber {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
        }
        .icon-rose {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
        }
        .company-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--brand) 12%, var(--surface-1));
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            margin-left: 0.5rem;
            flex-shrink: 0;
            border: 1px solid color-mix(in srgb, var(--brand) 25%, transparent);
        }
        .companies-table-card {
            background: var(--surface-1);
            border: 1px solid var(--border-default);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
        }
        .companies-filter-box {
            background: var(--surface-1);
            border: 1px solid var(--border-default);
            border-radius: 18px;
            padding: 1.25rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }
        .companies-actions-cell .dropdown-menu.show {
            z-index: 1055;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border: 1px solid var(--border-default);
            padding: 0.5rem;
        }
        .companies-actions-cell .dropdown-item {
            border-radius: 8px;
            padding: 0.5rem 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 500;
        }
    </style>
</head>
<body class="companies-module-page companies-list-page settings-page">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container-fluid px-lg-4 py-4">

        <!-- Header Card -->
        <div class="settings-header-card mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <nav class="small text-muted mb-2" aria-label="breadcrumb">
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                        <span class="mx-2">/</span>
                        <span class="text-primary fw-bold">بەڕێوبردنی کۆمپانیاکان</span>
                    </nav>
                    <h2 class="mb-1 d-flex align-items-center gap-2">
                        <i class="bi bi-building text-primary"></i>
                        بەڕێوبردنی کۆمپانیاکان
                    </h2>
                    <p class="text-muted mb-0">تۆمارکردن، بەدواداچوونی قەرز، دانەوەی پارە و مامەڵەکانی کڕین لەگەڵ کۆمپانیاکان</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?php echo url('user/purchases/index.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-receipt"></i> کڕینەکان
                    </a>
                    <a href="<?php echo url('user/companies/print/index.php'); ?>" class="btn btn-outline-primary">
                        <i class="bi bi-printer"></i> چاپکردنی لیستی قەرز
                    </a>
                    <a href="<?php echo url('user/companies/add.php'); ?>" class="btn btn-save">
                        <i class="bi bi-plus-lg"></i> زیادکردنی کۆمپانیای نوێ
                    </a>
                </div>
            </div>
        </div>

        <!-- Metric Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-sm-6">
                <div class="company-metric-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small fw-semibold d-block mb-1">گشتی کۆمپانیاکان</span>
                            <h3 class="fw-bold mb-0"><?php echo number_format((int)($stats['total_companies'] ?? 0)); ?></h3>
                            <small class="text-muted">کۆمپانیای تۆمارکراو</small>
                        </div>
                        <div class="company-metric-icon icon-blue">
                            <i class="bi bi-buildings"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-sm-6">
                <div class="company-metric-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small fw-semibold d-block mb-1">کۆمپانیای چالاک</span>
                            <h3 class="fw-bold mb-0 text-success"><?php echo number_format((int)($stats['active_companies'] ?? 0)); ?></h3>
                            <small class="text-success-emphasis">لە باری کارکردندا</small>
                        </div>
                        <div class="company-metric-icon icon-green">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-sm-6">
                <div class="company-metric-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small fw-semibold d-block mb-1">کۆمپانیای قەرزدار</span>
                            <h3 class="fw-bold mb-0 text-warning"><?php echo number_format((int)($stats['companies_with_debt'] ?? 0)); ?></h3>
                            <small class="text-warning-emphasis">قەرزی ماوە لای تۆ</small>
                        </div>
                        <div class="company-metric-icon icon-amber">
                            <i class="bi bi-exclamation-octagon"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-sm-6">
                <div class="company-metric-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small fw-semibold d-block mb-1">کۆی قەرزی ماوە</span>
                            <h4 class="fw-bold mb-0 text-danger"><?php echo htmlspecialchars(formatCurrencyAmount((float)($stats['total_debt'] ?? 0), 'IQD')); ?></h4>
                            <?php if ((float)($stats['total_debt_usd'] ?? 0) > 0): ?>
                                <div class="text-danger small fw-bold mt-1"><?php echo htmlspecialchars(formatCurrencyAmount((float)$stats['total_debt_usd'], 'USD')); ?></div>
                            <?php else: ?>
                                <small class="text-muted">بە دیناری عێراقی</small>
                            <?php endif; ?>
                        </div>
                        <div class="company-metric-icon icon-rose">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter and Search -->
        <div class="companies-filter-box mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-5 col-md-6">
                    <label class="form-label fw-semibold small text-muted">گەڕان بەدوای کۆمپانیا</label>
                    <div class="input-group">
                        <span class="input-group-text bg-body-tertiary"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="ناوی کۆمپانیا، ژمارەی مۆبایل یان ناونیشان..."
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                    </div>
                </div>
                <div class="col-lg-3 col-md-3">
                    <label class="form-label fw-semibold small text-muted">دۆخی کارکردن</label>
                    <select class="form-select" name="status">
                        <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>هەموو دۆخەکان</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>چالاک</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>ناچالاک</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6">
                    <button type="submit" class="btn btn-save w-100">
                        <i class="bi bi-funnel"></i> فیلتەرکردن
                    </button>
                </div>
                <div class="col-lg-2 col-md-12 col-6">
                    <a href="<?php echo url('user/companies/index.php'); ?>" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> پاککردنەوە
                    </a>
                </div>
            </form>
        </div>

        <!-- Companies Table Card -->
        <div class="companies-table-card">
            <div class="p-3 px-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-list-task text-primary"></i>
                    لیستی کۆمپانیا تۆمارکراوەکان
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill fs-7"><?php echo number_format($totalRecords); ?> کۆمپانیا</span>
                </h5>
                <a href="<?php echo url('user/companies/print/index.php'); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-printer"></i> چاپکردنی لیست
                </a>
            </div>
            
            <div class="p-0">
                <?php if (empty($companies)): ?>
                    <div class="text-center py-5 px-3">
                        <div class="company-metric-icon icon-blue mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2rem;">
                            <i class="bi bi-building"></i>
                        </div>
                        <h5 class="fw-bold">هیچ کۆمپانیایەک نەدۆزرایەوە</h5>
                        <p class="text-muted mb-3">
                            <?php if (!empty($search) || !empty($statusFilter)): ?>
                                هیچ ئەنجامێک بەپێی ئەم فیلتەرانە نەدۆزرایەوە، دەتوانیت گەڕانەکە ڕێکبخەیتەوە.
                            <?php else: ?>
                                هێشتا هیچ کۆمپانیایەکت لە سیستەم تۆمار نەکردووە.
                            <?php endif; ?>
                        </p>
                        <a href="<?php echo url('user/companies/add.php'); ?>" class="btn btn-save">
                            <i class="bi bi-plus-lg"></i> زیادکردنی یەکەم کۆمپانیا
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle companies-list-table">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">ناوی کۆمپانیا</th>
                                    <th>مۆبایل / پەیوەندی</th>
                                    <th>دۆخ</th>
                                    <th>قەرزی ماوە</th>
                                    <th>وەسڵی قەرز</th>
                                    <th>کۆی مامەڵە</th>
                                    <th>بەرواری تۆمارکردن</th>
                                    <th class="text-end pe-4">کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companies as $company): ?>
                                    <?php
                                        $rowDebt = (float)($company['computed_remaining_debt'] ?? 0);
                                        $rowDebtUsd = (float)($company['computed_remaining_debt_usd'] ?? 0);
                                        $firstChar = mb_substr(trim($company['name']), 0, 1, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td class="ps-4" data-label="ناو">
                                            <div class="d-flex align-items-center">
                                                <div class="company-avatar">
                                                    <?php echo htmlspecialchars($firstChar); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark-emphasis"><?php echo htmlspecialchars($company['name']); ?></div>
                                                    <?php if (!empty($company['address'])): ?>
                                                        <small class="text-muted d-block text-truncate" style="max-width: 180px;"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($company['address']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="تەلەفۆن">
                                            <?php if (!empty($company['phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($company['phone']); ?>" class="text-decoration-none fw-medium text-body">
                                                    <i class="bi bi-telephone text-muted me-1"></i><?php echo htmlspecialchars($company['phone']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="دۆخ">
                                            <?php if ($company['status'] === 'active'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill">چالاک</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-1 rounded-pill">ناچالاک</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="قەرز">
                                            <?php if ($rowDebt > 0): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-bold">
                                                    <?php echo htmlspecialchars(formatCurrencyAmount($rowDebt, 'IQD')); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill">
                                                    ٠ دینار
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($rowDebtUsd > 0): ?>
                                                <div class="text-danger fw-bold small mt-1">
                                                    <?php echo htmlspecialchars(formatCurrencyAmount($rowDebtUsd, 'USD')); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="وەسڵی قەرز">
                                            <?php if ((int)$company['installment_count'] > 0): ?>
                                                <a href="<?php echo url('user/purchases/index.php?company_id=' . (int)$company['id'] . '&payment_type=debt'); ?>" class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill text-decoration-none" title="بینینی وەسڵەکان">
                                                    <i class="bi bi-file-earmark-text me-1"></i><?php echo (int)$company['installment_count']; ?> وەسڵ
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="مامەڵە">
                                            <span class="fw-semibold"><?php echo number_format((int)$company['debt_count'] + (int)$company['payment_count']); ?></span>
                                            <small class="text-muted">تۆمار</small>
                                        </td>
                                        <td data-label="بەروار">
                                            <small class="text-muted"><?php echo date('Y/m/d', strtotime($company['created_at'])); ?></small>
                                        </td>
                                        <td class="text-end pe-4 text-nowrap companies-actions-cell" data-label="کردارەکان">
                                            <a href="<?php echo url('user/companies/debts.php?company_id=' . (int)$company['id']); ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-cash-stack"></i> قەرزەکان
                                            </a>
                                            <div class="dropdown d-inline-block">
                                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                    کردارەکان
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                    <li>
                                                        <a class="dropdown-item" href="<?php echo url('user/companies/edit.php?id=' . (int)$company['id']); ?>">
                                                            <i class="bi bi-pencil-square text-primary"></i> دەستکاری کۆمپانیا
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="<?php echo url('user/companies/debts.php?company_id=' . (int)$company['id']); ?>">
                                                            <i class="bi bi-cash-coin text-success"></i> وەرگرتن / دانەوەی پارە
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="<?php echo url('user/purchases/index.php?company_id=' . (int)$company['id']); ?>">
                                                            <i class="bi bi-receipt text-info"></i> پسوولەکانی کڕین
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="<?php echo url('user/purchases/add.php?company_id=' . (int)$company['id']); ?>">
                                                            <i class="bi bi-plus-circle text-success"></i> وەسڵی نوێی کڕین
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="#" onclick="deleteCompany(<?php echo (int)$company['id']; ?>); return false;">
                                                            <i class="bi bi-trash"></i> سڕینەوەی کۆمپانیا
                                                        </a>
                                                    </li>
                                                </ul>
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
            <nav aria-label="لاپەڕە" class="mt-4">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php
                            $queryParams = $_GET;
                            $queryParams['page'] = $page - 1;
                            echo url('user/companies/index.php?' . http_build_query($queryParams));
                        ?>">
                            <i class="bi bi-chevron-right"></i> پێشوو
                        </a>
                    </li>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    if ($startPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php
                                $queryParams = $_GET;
                                $queryParams['page'] = 1;
                                echo url('user/companies/index.php?' . http_build_query($queryParams));
                            ?>">1</a>
                        </li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php
                                $queryParams = $_GET;
                                $queryParams['page'] = $i;
                                echo url('user/companies/index.php?' . http_build_query($queryParams));
                            ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php
                                $queryParams = $_GET;
                                $queryParams['page'] = $totalPages;
                                echo url('user/companies/index.php?' . http_build_query($queryParams));
                            ?>"><?php echo $totalPages; ?></a>
                        </li>
                    <?php endif; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php
                            $queryParams = $_GET;
                            $queryParams['page'] = $page + 1;
                            echo url('user/companies/index.php?' . http_build_query($queryParams));
                        ?>">
                            دواتر <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill"></i> دڵنیاییکردنەوەی سڕینەوە
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <div class="modal-body py-3">
                    <p class="mb-0 text-muted">ئایا دڵنیایت لە سڕینەوەی ئەم کۆمپانیایە؟ ئەم کردارە ناگەڕێتەوە.</p>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="company_id" id="deleteCompanyId" value="">
                        <button type="submit" name="delete" value="1" class="btn btn-danger">
                            <i class="bi bi-trash"></i> بەڵێ، بسڕەوە
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteCompany(id) {
            document.getElementById('deleteCompanyId').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        document.querySelectorAll('.companies-list-table [data-bs-toggle="dropdown"]').forEach(function (toggle) {
            bootstrap.Dropdown.getOrCreateInstance(toggle, {
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                }
            });
        });
    </script>
</body>
</html>
