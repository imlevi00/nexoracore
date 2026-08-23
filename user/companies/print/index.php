<?php
require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/company_computed_debt.php';

// تاقیکردنی داخڵبوون
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'companies.view', [
    'route' => '/user/companies/print/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

// وەرگرتنی لیستی کۆمپانیاکان بۆ چاپ (قەرزی ماوە + ژمارەی وەسڵە قەرزەکان)
$computedDebtExpr = company_computed_remaining_debt_expr_sql('c');
$computedDebtExprUsd = company_computed_remaining_debt_expr_sql('c', 'USD');
$query = "
    SELECT
        c.id,
        c.name,
        c.phone,
        $computedDebtExpr AS computed_remaining_debt,
        $computedDebtExprUsd AS computed_remaining_debt_usd,
        (
            SELECT COUNT(*) 
            FROM purchase_receipts pr 
            WHERE pr.company_id = c.id 
              AND pr.user_id = c.user_id
              AND pr.payment_type = 'debt'
              AND pr.status = 'active'
        ) AS debt_receipts_count
    FROM companies c
    WHERE c.user_id = ?
    ORDER BY c.name ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userId);
$stmt->execute();
$companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپکردنی لیستی قەرزی کۆمپانیاکان - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .print-header {
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
        }

        .print-controls {
            margin-bottom: 1rem;
        }

        .table thead th {
            background-color: #f1f3f5;
        }

        @media print {
            body {
                background-color: #ffffff;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .card,
            html[data-bs-theme='dark'] .table,
            html[data-bs-theme='dark'] .table th,
            html[data-bs-theme='dark'] .table td,
            html[data-bs-theme='dark'] .text-muted {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #d1d5db !important;
            }

            .print-controls {
                display: none !important;
            }

            .print-header {
                margin-bottom: 0.5rem;
                border-bottom-width: 1px;
            }

            .card {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <div class="print-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">
                    <?php echo htmlspecialchars($currentUser['business_name']); ?>
                </h5>
                <small class="text-muted">لیستی قەرزی کۆمپانیاکان بۆ چاپ</small>
            </div>
            <div class="text-muted small">
                <div>بەروار: <?php echo date('Y/m/d'); ?></div>
                <div>کات: <?php echo date('H:i'); ?></div>
            </div>
        </div>

        <div class="print-controls d-flex justify-content-between align-items-center">
            <div>
                <button class="btn btn-primary btn-sm" onclick="window.print()">
                    چاپکردن
                </button>
                <a href="<?php echo url('user/companies/index.php'); ?>" class="btn btn-outline-secondary btn-sm">
                    گەڕانەوە بۆ لیستی کۆمپانیاکان
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
                <?php if (empty($companies)): ?>
                    <div class="text-center py-5">
                        <p class="text-muted mb-0">هیچ کۆمپانیایەک بۆ چاپ نییە</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th>ناوی کۆمپانیا</th>
                                    <th>ژمارەی موبایل</th>
                                    <th>بڕی قەرزی ماوە (دینار)</th>
                                    <th>بڕی قەرزی ماوە (دۆلار)</th>
                                    <th>ژمارەی وەسڵە قەرزە چالاکەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $index = 1; ?>
                                <?php foreach ($companies as $company): ?>
                                    <tr>
                                        <td><?php echo $index++; ?></td>
                                        <td><?php echo htmlspecialchars($company['name']); ?></td>
                                        <td><?php echo htmlspecialchars($company['phone'] ?: '-'); ?></td>
                                        <td><?php echo number_format((float)($company['computed_remaining_debt'] ?? 0)); ?></td>
                                        <td><?php echo number_format((float)($company['computed_remaining_debt_usd'] ?? 0), 2); ?></td>
                                        <td><?php echo (int)($company['debt_receipts_count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-center mt-2">
            <div class="text-center px-4 py-2 border border-2 border-primary rounded-pill" style="border-style: dashed;">
                <div class="fw-semibold">سیستەمی NexoraCore</div>
            </div>
        </div>
    </div>
</body>
</html>

