<?php
/**
 * بەڕێوبردنی کۆمپانیاکان - user/companies/index.php
 */
 
require_once '../../config/config.php';
require_once '../../config/security.php';
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
    <title>بەڕێوبردنی کۆمپانیاکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        .companies-page .table td,
        .companies-page .table th {
            vertical-align: middle;
        }

        /* مێنیوی "زیاتر" لەسەر هەموو شتەکان دەربکەوێت */
        .companies-actions-cell .dropdown-menu.show {
            z-index: 1055;
        }

        html[data-bs-theme='dark'] body.companies-page {
            background-color: #0b1220 !important;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] .companies-page .card,
        html[data-bs-theme='dark'] .companies-page .card-header,
        html[data-bs-theme='dark'] .companies-page .card-body,
        html[data-bs-theme='dark'] .companies-page .modal-content,
        html[data-bs-theme='dark'] .companies-page .dropdown-menu {
            background-color: #111827;
            color: #e5e7eb;
            border-color: #253247;
        }

        html[data-bs-theme='dark'] .companies-page .alert-warning {
            background-color: #3b2f10;
            color: #fef3c7;
        }

        html[data-bs-theme='dark'] .companies-page .alert-warning .text-warning {
            color: #fbbf24 !important;
        }

        html[data-bs-theme='dark'] .companies-page .alert-warning .text-dark {
            color: #fef3c7 !important;
        }

        html[data-bs-theme='dark'] .companies-page .card-header.bg-white {
            background-color: #111827 !important;
        }

        html[data-bs-theme='dark'] .companies-page .table-light,
        html[data-bs-theme='dark'] .companies-page .table-light th {
            background-color: #172132 !important;
            color: #cbd5e1;
            border-color: #253247;
        }

        html[data-bs-theme='dark'] .companies-page .table td {
            color: #e5e7eb;
            border-color: #253247;
        }

        html[data-bs-theme='dark'] .companies-page .table-hover > tbody > tr:hover > * {
            background-color: #1b2738;
            color: #f8fafc;
        }

        html[data-bs-theme='dark'] .companies-page .text-muted,
        html[data-bs-theme='dark'] .companies-page small.text-muted {
            color: #94a3b8 !important;
        }

        html[data-bs-theme='dark'] .companies-page .form-control,
        html[data-bs-theme='dark'] .companies-page .form-select,
        html[data-bs-theme='dark'] .companies-page .page-link,
        html[data-bs-theme='dark'] .companies-page .btn-outline-secondary {
            background-color: #0f172a;
            color: #e5e7eb;
            border-color: #334155;
        }

        html[data-bs-theme='dark'] .companies-page .page-link:hover,
        html[data-bs-theme='dark'] .companies-page .btn-outline-secondary:hover {
            background-color: #334155;
            color: #f8fafc;
        }

        html[data-bs-theme='dark'] .companies-page .dropdown-item {
            color: #e2e8f0;
        }

        html[data-bs-theme='dark'] .companies-page .dropdown-item:hover,
        html[data-bs-theme='dark'] .companies-page .dropdown-item:focus {
            background-color: #1f2937;
            color: #f8fafc;
        }

        html[data-bs-theme='dark'] .companies-page .dropdown-divider {
            border-color: #334155;
        }
    </style>
</head>
<body class="companies-module-page companies-list-page bg-light companies-page">

        <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">

        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center hub-page-header flex-wrap gap-2 mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-building text-primary"></i>
                        بەڕێوبردنی کۆمپانیاکان
                    </h1>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?php echo url('user/purchases/index.php'); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-receipt"></i> کڕینەکان
                        </a>
                        <a href="<?php echo url('user/companies/print/index.php'); ?>" class="btn btn-outline-primary">
                            <i class="bi bi-printer"></i> چاپکردنی لیستی قەرز
                        </a>
                        <a href="<?php echo url('user/companies/add.php'); ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> زیادکردنی کۆمپانیای نوێ
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">گشتی کۆمپانیاکان</h6>
                                <h2 class="card-title mb-0"><?php echo number_format((int)($stats['total_companies'] ?? 0)); ?></h2>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-building display-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کۆمپانیای چالاک</h6>
                                <h2 class="card-title mb-0"><?php echo number_format((int)($stats['active_companies'] ?? 0)); ?></h2>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-check-circle display-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کۆمپانیای قەرزدار</h6>
                                <h2 class="card-title mb-0"><?php echo number_format((int)($stats['companies_with_debt'] ?? 0)); ?></h2>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-exclamation-triangle display-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm bg-danger text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کۆی قەرز</h6>
                                <h2 class="card-title mb-0 fs-4"><?php echo htmlspecialchars(formatCurrencyAmount((float)($stats['total_debt'] ?? 0), 'IQD')); ?></h2>
                                <?php if ((float)($stats['total_debt_usd'] ?? 0) > 0): ?>
                                    <div class="mt-1 fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount((float)$stats['total_debt_usd'], 'USD')); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-currency-exchange display-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm companies-filter-card">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">گەڕان</label>
                                <input type="text" class="form-control" name="search"
                                       value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="ناو، تەلەفۆن یان ناونیشان..."
                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">دۆخ</label>
                                <select class="form-select" name="status">
                                    <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>هەموو</option>
                                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>چالاک</option>
                                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>ناچالاک</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-secondary w-100">
                                    <i class="bi bi-search"></i> گەڕان
                                </button>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <a href="<?php echo url('user/companies/index.php'); ?>" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-clockwise"></i> ڕێکخستنەوە
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul"></i>
                            لیستی کۆمپانیاکان
                        </h5>
                        <a href="<?php echo url('user/companies/print/index.php'); ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-printer"></i> چاپکردنی لیست
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($companies)): ?>
                            <div class="text-center py-5 px-3">
                                <i class="bi bi-building display-3 text-muted"></i>
                                <p class="text-muted mt-3 mb-0">
                                    <?php if (!empty($search) || !empty($statusFilter)): ?>
                                        هیچ کۆمپانیاک بە ئەم فیلتەرانە نەدۆزرایەوە
                                    <?php else: ?>
                                        هیچ کۆمپانیاک تۆمار نەکراوە
                                    <?php endif; ?>
                                </p>
                                <a href="<?php echo url('user/companies/add.php'); ?>" class="btn btn-primary mt-3">
                                    <i class="bi bi-plus-lg"></i> زیادکردنی کۆمپانیای نوێ
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle companies-list-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ناو</th>
                                            <th>تەلەفۆن</th>
                                            <th>دۆخ</th>
                                            <th>قەرز</th>
                                            <th>وەسڵی قەرز</th>
                                            <th>مامەڵە</th>
                                            <th>بەروار</th>
                                            <th class="text-end">کردارەکان</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($companies as $company): ?>
                                            <tr>
                                                <td class="fw-semibold" data-label="ناو">
                                                    <i class="bi bi-building text-primary me-1"></i>
                                                    <?php echo htmlspecialchars($company['name']); ?>
                                                </td>
                                                <td data-label="تەلەفۆن"><?php echo !empty($company['phone']) ? htmlspecialchars($company['phone']) : '—'; ?></td>
                                                <td data-label="دۆخ">
                                                    <?php if ($company['status'] === 'active'): ?>
                                                        <span class="badge bg-success">چالاک</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">ناچالاک</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="قەرز">
                                                    <?php
                                                        $rowDebt = (float)($company['computed_remaining_debt'] ?? 0);
                                                        $rowDebtUsd = (float)($company['computed_remaining_debt_usd'] ?? 0);
                                                    ?>
                                                    <span class="<?php echo $rowDebt > 0 ? 'text-danger fw-semibold' : 'text-success'; ?>">
                                                        <?php echo htmlspecialchars(formatCurrencyAmount($rowDebt, 'IQD')); ?>
                                                    </span>
                                                    <?php if ($rowDebtUsd > 0): ?>
                                                        <div class="text-danger fw-semibold small mt-1"><?php echo htmlspecialchars(formatCurrencyAmount($rowDebtUsd, 'USD')); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="وەسڵی قەرز">
                                                    <?php if ((int)$company['installment_count'] > 0): ?>
                                                        <span class="badge bg-warning text-dark"><?php echo (int)$company['installment_count']; ?> وەسڵ</span>
                                                        <a href="<?php echo url('user/purchases/index.php?company_id=' . (int)$company['id'] . '&payment_type=debt'); ?>" class="btn btn-link btn-sm p-0 ms-1" title="بینین">بینین</a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="مامەڵە"><?php echo number_format((int)$company['debt_count'] + (int)$company['payment_count']); ?></td>
                                                <td data-label="بەروار"><small class="text-muted"><?php echo date('Y/m/d', strtotime($company['created_at'])); ?></small></td>
                                                <td class="text-end text-nowrap companies-actions-cell" data-label="کردارەکان">
                                                    <a href="<?php echo url('user/companies/debts.php?company_id=' . (int)$company['id']); ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-list-check"></i> قەرزەکان
                                                    </a>
                                                    <div class="dropdown d-inline-block">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                            زیاتر
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a class="dropdown-item" href="<?php echo url('user/companies/edit.php?id=' . (int)$company['id']); ?>">
                                                                    <i class="bi bi-pencil-square text-primary"></i> دەستکاری
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" href="<?php echo url('user/companies/debts.php?company_id=' . (int)$company['id']); ?>">
                                                                    <i class="bi bi-cash-coin text-success"></i> دانەوەی پارە
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" href="<?php echo url('user/purchases/index.php?company_id=' . (int)$company['id']); ?>">
                                                                    <i class="bi bi-receipt text-info"></i> کڕینەکان
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" href="<?php echo url('user/purchases/add.php?company_id=' . (int)$company['id']); ?>">
                                                                    <i class="bi bi-plus-circle text-success"></i> کڕینی نوێ
                                                                </a>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <a class="dropdown-item text-danger" href="#" onclick="deleteCompany(<?php echo (int)$company['id']; ?>); return false;">
                                                                    <i class="bi bi-trash"></i> سڕینەوە
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
            </div>
        </div>

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

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">دڵنیاییکردنەوە</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">دڵنیای لە سڕینەوەی ئەم کۆمپانیایە؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەز</button>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="company_id" id="deleteCompanyId" value="">
                        <button type="submit" name="delete" value="1" class="btn btn-danger">سڕینەوە</button>
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

        // چارەسەری بڕینی مێنیوی "زیاتر" لەلایەن overflow ـی .table-responsive
        // مێنیوەکە بە strategy=fixed دەربکەوێت تا لەسەر هەموو شتەکان بێت نەک بەژێریانەوە
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
