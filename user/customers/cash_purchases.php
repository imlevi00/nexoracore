<?php
/**
 * Customer Cash Purchases Page
 * Displays all cash purchases made by customers with filtering options
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// Check authentication
if (!isUser()) {
    header('Location: ' . url('user/auth/login.php'));
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Get filter parameters
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = cleanInput($_GET['search'] ?? '');

// Build WHERE clause
$whereConditions = ["ccp.user_id = ?"];
$params = [$userId];
$paramTypes = 'i';

if ($customerId > 0) {
    $whereConditions[] = "ccp.customer_id = ?";
    $params[] = $customerId;
    $paramTypes .= 'i';
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(ccp.purchase_date) >= ?";
    $params[] = $dateFrom;
    $paramTypes .= 's';
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(ccp.purchase_date) <= ?";
    $params[] = $dateTo;
    $paramTypes .= 's';
}

if (!empty($search)) {
    $whereConditions[] = "(c.name LIKE ? OR ccp.invoice_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $paramTypes .= 'ss';
}

$whereClause = implode(' AND ', $whereConditions);

// Get customers for filter dropdown
$customersQuery = "SELECT id, name, phone FROM customers WHERE user_id = ? AND status = 'active' ORDER BY name";
$customersStmt = $conn->prepare($customersQuery);
$customersStmt->bind_param('i', $userId);
$customersStmt->execute();
$customers = $customersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$customersStmt->close();

// Get cash purchases with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$countQuery = "
    SELECT COUNT(*) as total
    FROM customer_cash_purchases ccp
    JOIN customers c ON ccp.customer_id = c.id
    WHERE $whereClause
";

$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param($paramTypes, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);
$countStmt->close();

$purchasesQuery = "
    SELECT 
        ccp.id,
        ccp.sale_id,
        ccp.invoice_number,
        ccp.total_amount,
        ccp.discount,
        ccp.final_amount,
        ccp.purchase_date,
        COALESCE(s.currency, 'IQD') as currency,
        c.name as customer_name,
        c.phone as customer_phone,
        c.address as customer_address
    FROM customer_cash_purchases ccp
    JOIN customers c ON ccp.customer_id = c.id
    LEFT JOIN sales s ON ccp.sale_id = s.id
    WHERE $whereClause
    ORDER BY ccp.purchase_date DESC
    LIMIT ? OFFSET ?
";

$purchasesStmt = $conn->prepare($purchasesQuery);
$params[] = $limit;
$params[] = $offset;
$paramTypes .= 'ii';
$purchasesStmt->bind_param($paramTypes, ...$params);
$purchasesStmt->execute();
$purchases = $purchasesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$purchasesStmt->close();

// Get summary statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_purchases,
        SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN ccp.final_amount ELSE 0 END) as total_amount_iqd,
        SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN ccp.final_amount ELSE 0 END) as total_amount_usd,
        AVG(ccp.final_amount) as avg_amount,
        MIN(ccp.purchase_date) as first_purchase,
        MAX(ccp.purchase_date) as last_purchase
    FROM customer_cash_purchases ccp
    JOIN customers c ON ccp.customer_id = c.id
    LEFT JOIN sales s ON ccp.sale_id = s.id
    WHERE $whereClause
";

$statsStmt = $conn->prepare($statsQuery);
$statsParamTypes = substr($paramTypes, 0, -2); // Remove the 'ii' for limit and offset
$statsStmt->bind_param($statsParamTypes, ...array_slice($params, 0, -2)); // Remove limit and offset
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$pageTitle = 'کڕینەکانی کاش - کڕیاران';
$bodyClass = 'customers-module-page customers-cash-page customers-page';
$additionalCSS = ['customers/customers-pages.css', 'customers/customers-dark.css', 'customers/customers-responsive.css'];
include '../../includes/header.php';
?>

<div class="container-fluid customers-page-content cu-wrap">
    <header class="cu-hero">
        <div>
            <div class="cu-kicker"><i class="bi bi-cash-coin"></i> کڕیاران</div>
            <h1><i class="bi bi-wallet2"></i> کڕینەکانی کاش</h1>
            <p class="cu-hero-sub">هەموو کڕینە کاشەکانی کڕیاران بە فلتەر و وەسڵ بگەڕێ</p>
            <div class="cu-hero-pills">
                <span class="cu-pill"><i class="bi bi-receipt"></i> <?php echo number_format($stats['total_purchases']); ?> کڕین</span>
            </div>
        </div>
        <div class="cu-actions">
            <a class="cu-btn cu-btn-ghost" href="<?php echo url('user/customers/index.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </header>

    <div class="cu-stats cu-stats-4">
        <div class="cu-stat" style="--stat-accent:#0ea5e9">
            <div class="cu-stat-icon"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="cu-stat-label">کۆی کڕینەکان</div>
                <div class="cu-stat-value"><?php echo number_format($stats['total_purchases']); ?></div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#10b981">
            <div class="cu-stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div>
                <div class="cu-stat-label">کۆی بڕی پارە</div>
                <div class="cu-stat-value">
                    <?php 
                    $totalIQD = $stats['total_amount_iqd'] ?? 0;
                    $totalUSD = $stats['total_amount_usd'] ?? 0;
                    if ($totalUSD > 0 && $totalIQD > 0) {
                        echo formatMoney($totalIQD, 'IQD') . ' + ' . formatMoney($totalUSD, 'USD');
                    } elseif ($totalUSD > 0) {
                        echo formatMoney($totalUSD, 'USD');
                    } else {
                        echo formatMoney($totalIQD, 'IQD');
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#6366f1">
            <div class="cu-stat-icon"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="cu-stat-label">نرخی ناوەند</div>
                <div class="cu-stat-value"><?php echo formatMoney($stats['avg_amount']); ?></div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#f59e0b">
            <div class="cu-stat-icon"><i class="bi bi-calendar-event"></i></div>
            <div>
                <div class="cu-stat-label">کڕینی کۆتایی</div>
                <div class="cu-stat-value"><?php echo $stats['last_purchase'] ? date('Y/m/d', strtotime($stats['last_purchase'])) : 'هیچ'; ?></div>
            </div>
        </div>
    </div>

    <div class="cu-panel mb-4 customer-filter-card">
        <div class="cu-panel-head"><i class="bi bi-funnel"></i> فلتەرەکان</div>
        <div class="cu-panel-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="customer_id" class="form-label">کڕیار</label>
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
                        <option value="">هەموو کڕیاران</option>
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
                    <label for="date_from" class="form-label">لە ڕۆژی</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">بۆ ڕۆژی</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-3">
                    <label for="search" class="form-label">گەڕان</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="گەڕان بە ژمارەی فاتورە..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="cu-btn cu-btn-primary w-100">
                            <i class="bi bi-search me-1"></i>گەڕان
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="cu-panel">
        <div class="cu-panel-head">
            <span>کڕینەکانی کاش</span>
            <span class="text-muted small">کۆی <?php echo number_format($totalRecords); ?> کڕین</span>
        </div>
        <div class="p-0">
            <?php if (empty($purchases)): ?>
                <div class="cu-empty">
                    <div class="cu-empty-icon"><i class="bi bi-inbox"></i></div>
                    <h3>هیچ کڕینێک نەدۆزرایەوە</h3>
                    <p>لەگەڵ فلتەرەکانی هەڵبژاردوو هیچ کڕینێک نەدۆزرایەوە</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover customers-cash-table">
                        <thead class="table-light">
                            <tr>
                                <th>ژمارەی فاتورە</th>
                                <th>کڕیار</th>
                                <th>تەلەفۆن</th>
                                <th>کۆی بڕ</th>
                                <th>داشکاندن</th>
                                <th>بڕی کۆتایی</th>
                                <th>ڕۆژی کڕین</th>
                                <th>کردارەکان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $purchase): ?>
                                <tr>
                                    <td data-label="ژمارەی فاتورە">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($purchase['invoice_number']); ?></span>
                                    </td>
                                    <td data-label="کڕیار">
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($purchase['customer_name']); ?></div>
                                            <?php if ($purchase['customer_address']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($purchase['customer_address']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="تەلەفۆن">
                                        <?php if ($purchase['customer_phone']): ?>
                                            <a href="tel:<?php echo htmlspecialchars($purchase['customer_phone']); ?>" 
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($purchase['customer_phone']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="کۆی بڕ" class="text-end"><?php echo formatMoney($purchase['total_amount'], $purchase['currency'] ?? 'IQD'); ?></td>
                                    <td data-label="داشکاندن" class="text-end">
                                        <?php if ($purchase['discount'] > 0): ?>
                                            <span class="text-success">-<?php echo formatMoney($purchase['discount'], $purchase['currency'] ?? 'IQD'); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="بڕی کۆتایی" class="text-end fw-bold text-primary">
                                        <?php echo formatMoney($purchase['final_amount'], $purchase['currency'] ?? 'IQD'); ?>
                                    </td>
                                    <td data-label="ڕۆژی کڕین">
                                        <div>
                                            <div><?php echo date('Y/m/d', strtotime($purchase['purchase_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($purchase['purchase_date'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php 
                                            $printUrl = url('user/receipts/print.php?id=' . $purchase['sale_id']);
                                            $viewUrl = url('user/sales/view.php?id=' . $purchase['sale_id']);
                                            ?>
                                            <a href="<?php echo $printUrl; ?>" class="btn btn-outline-primary" target="_blank" title="پڕینتی فاتورە">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                            <a href="<?php echo $viewUrl; ?>" class="btn btn-outline-info" target="_blank" title="بینینی وردەکاری">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if (!empty($purchase['sale_id'])): ?>
                                            <a href="<?php echo url('user/pos/sales.php?edit_sale_id=' . $purchase['sale_id']); ?>"
                                               class="btn btn-outline-warning"
                                               title="دەستکاریکردنی وەسڵ">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-outline-info" 
                                                    title="پرێنتی A4" 
                                                    onclick="showPrintA4Modal(<?php echo $purchase['sale_id']; ?>)">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Print A4 Modal Functions
function showPrintA4Modal(saleId) {
    // Reset all checkboxes to checked (default)
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = true);
    
    // Store sale ID
    document.getElementById('printA4Modal').setAttribute('data-sale-id', saleId);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('printA4Modal'));
    modal.show();
}

function selectAllFields() {
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = true);
}

function deselectAllFields() {
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = false);
}

function printA4Receipt() {
    const saleId = document.getElementById('printA4Modal').getAttribute('data-sale-id');
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]:checked');
    
    // If no fields selected, show all fields (default behavior)
    let fields = [];
    if (checkboxes.length > 0) {
        checkboxes.forEach(cb => {
            fields.push(cb.value);
        });
    }
    
    // Build URL
    let url = '<?php echo url("user/pos/receipt_a4.php"); ?>?id=' + saleId;
    if (fields.length > 0) {
        url += '&fields=' + fields.join(',');
    }
    
    // Open in new tab
    window.open(url, '_blank');
    
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('printA4Modal'));
    modal.hide();
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

    function getCustomerMeta(customer) {
        const metaParts = [];
        if (customer.phone) metaParts.push(customer.phone);
        const debtMatch = customer.label.match(/\(قەرز:[^)]+\)/);
        if (debtMatch) metaParts.push(debtMatch[0]);
        return metaParts.join(' - ');
    }

    function renderDropdown() {
        const noResultHtml = '<div class="customer-option"><span class="meta">هیچ کڕیارێک نەدۆزرایەوە</span></div>';
        const allOptionHtml = `
            <div class="customer-option ${customerSelect.value === '' ? 'active' : ''}" data-id="" role="option">
                <span class="name">${escapeHtml(allCustomerOption ? allCustomerOption.textContent.trim() : 'هەموو کڕیاران')}</span>
                <span class="meta">بۆ نیشاندانی هەموو ئەنجامەکان</span>
            </div>
        `;

        const customersHtml = visibleCustomers.map((customer, index) => `
            <div class="customer-option ${index === activeIndex ? 'active' : ''}" data-id="${customer.id}" role="option">
                <span class="name">${escapeHtml(customer.name || 'بێ ناو')}</span>
                <span class="meta">${escapeHtml(getCustomerMeta(customer))}</span>
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

<!-- Print A4 Field Selection Modal -->
<div class="modal fade" id="printA4Modal" tabindex="-1" aria-labelledby="printA4ModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printA4ModalLabel">
                    <i class="bi bi-file-earmark-text"></i> هەڵبژاردنی فیلدەکان بۆ چاپی A4
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllFields()">
                        <i class="bi bi-check-all"></i> هەموو هەڵبژێرە
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllFields()">
                        <i class="bi bi-x-square"></i> هیچ هەڵمەبژێرە
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="bi bi-table"></i> فیلدەکانی خشتەی کاڵاکان
                        </h6>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="product_name" id="field_product_name" checked>
                            <label class="form-check-label" for="field_product_name">
                                ناوی کاڵا
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="product_image" id="field_product_image" checked>
                            <label class="form-check-label" for="field_product_image">
                                وێنەی کاڵا
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="barcode" id="field_barcode" checked>
                            <label class="form-check-label" for="field_barcode">
                                بارکۆد
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="quantity" id="field_quantity" checked>
                            <label class="form-check-label" for="field_quantity">
                                بڕ
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="unit" id="field_unit" checked>
                            <label class="form-check-label" for="field_unit">
                                یەکە
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="unit_price" id="field_unit_price" checked>
                            <label class="form-check-label" for="field_unit_price">
                                نرخی یەکە
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="total" id="field_total" checked>
                            <label class="form-check-label" for="field_total">
                                کۆی نرخ
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-success mb-3">
                            <i class="bi bi-info-circle"></i> فیلدەکانی دیکە
                        </h6>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="receipt_number" id="field_receipt_number" checked>
                            <label class="form-check-label" for="field_receipt_number">
                                ژمارەی وەسڵ
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="date" id="field_date" checked>
                            <label class="form-check-label" for="field_date">
                                بەروار
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="time" id="field_time" checked>
                            <label class="form-check-label" for="field_time">
                                کاتژمێر
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="customer_info" id="field_customer_info" checked>
                            <label class="form-check-label" for="field_customer_info">
                                زانیاری کڕیار
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="totals" id="field_totals" checked>
                            <label class="form-check-label" for="field_totals">
                                کۆی گشتی
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="unit_totals_summary" id="field_unit_totals_summary" checked>
                            <label class="form-check-label" for="field_unit_totals_summary">
                                کۆی بڕەکان بەپێی یەکە (لاپەڕە و کۆتایی)
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="payment_method" id="field_payment_method" checked>
                            <label class="form-check-label" for="field_payment_method">
                                شێوازی پارەدان
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="discount" id="field_discount" checked>
                            <label class="form-check-label" for="field_discount">
                                داشکاندن
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="tax" id="field_tax" checked>
                            <label class="form-check-label" for="field_tax">
                                باج
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="customer_note" id="field_customer_note" checked>
                            <label class="form-check-label" for="field_customer_note">
                                تێبینی کڕیار
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> داخستن
                </button>
                <button type="button" class="btn btn-primary" onclick="printA4Receipt()">
                    <i class="bi bi-printer"></i> چاپکردن
                </button>
            </div>
        </div>
    </div>
</div>

<style>
html[data-bs-theme='dark'] .theme-footer {
    background: #111827 !important;
    border-color: #374151 !important;
}

html[data-bs-theme='dark'] .theme-footer .text-muted {
    color: #9ca3af !important;
}

html[data-bs-theme='dark'] .text-gray-800,
html[data-bs-theme='dark'] .font-weight-bold,
html[data-bs-theme='dark'] .text-xs {
    color: #e5e7eb !important;
}

html[data-bs-theme='dark'] .text-gray-300 {
    color: #6b7280 !important;
}

html[data-bs-theme='dark'] .table-light,
html[data-bs-theme='dark'] .table-light th {
    background-color: #1f2937 !important;
    color: #d1d5db !important;
    border-color: #374151 !important;
}

html[data-bs-theme='dark'] .card {
    border-color: #374151;
    background-color: #111827;
}

html[data-bs-theme='dark'] .modal-content,
html[data-bs-theme='dark'] .dropdown-menu {
    background-color: #111827 !important;
    border-color: #374151 !important;
    color: #e5e7eb !important;
}

.card {
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.table th {
    border-top: none;
    font-weight: 600;
    color: #495057;
}

.badge {
    font-size: 0.75rem;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

.page-title {
    color: #495057;
    font-weight: 600;
}

.breadcrumb {
    background: none;
    padding: 0;
}

.breadcrumb-item + .breadcrumb-item::before {
    content: ">";
    color: #6c757d;
}

/* Reduce spacing between checkbox and label in print modal */
#printA4Modal .form-check {
    display: flex !important;
    align-items: center !important;
    gap: 0.5em !important; /* Small gap between checkbox and label */
    padding-right: 0 !important; /* Remove any default padding that might push it */
}
#printA4Modal .form-check-input {
    margin-right: 0 !important;
    margin-left: 0 !important;
    margin-top: 0 !important; /* Remove any default vertical margin */
    flex-shrink: 0; /* Prevent checkbox from shrinking */
}
#printA4Modal .form-check-label {
    padding-right: 0 !important;
    margin-right: 0 !important;
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

<?php include '../../includes/footer.php'; ?>
