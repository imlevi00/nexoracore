<?php
// product_returns.php - بەڕێوەبردنی گەڕانەوەی کاڵاکان

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// چیکردنی ئۆتۆریزەیشن
requireLogin();

$db = new Database();
$conn = $db->getConnection();
$userId = getCurrentUserId();

// پرۆسێسکردنی فۆرمەکان
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_return') {
        $return_number = 'RET-' . date('Ymd') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $customer_id = intval($_POST['customer_id'] ?? 0) ?: null;
        $customer_name = sanitizeInput($_POST['customer_name']);
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $unit_price = floatval($_POST['unit_price']);
        $return_reason = sanitizeInput($_POST['return_reason']);
        $return_type = sanitizeInput($_POST['return_type']);
        $original_sale_id = intval($_POST['original_sale_id'] ?? 0) ?: null;
        $notes = sanitizeInput($_POST['notes']);
        
        $total_amount = $quantity * $unit_price;
        
        // وەرگرتنی زانیاری کاڵاکە
        $stmt = $conn->prepare("SELECT name FROM products WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $product_id, $userId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($product && $quantity > 0) {
            $conn->begin_transaction();
            
            try {
                // زیادکردنی گەڕانەوەکە
                $stmt = $conn->prepare("INSERT INTO product_returns (user_id, return_number, customer_id, customer_name, product_id, product_name, quantity, unit_price, total_amount, return_reason, return_type, original_sale_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isisisiddssis", $userId, $return_number, $customer_id, $customer_name, $product_id, $product['name'], $quantity, $unit_price, $total_amount, $return_reason, $return_type, $original_sale_id, $notes);
                $stmt->execute();
                $stmt->close();
                
                // زیادکردنی کاڵاکە بۆ ستۆک
                if ($return_type === 'refund' || $return_type === 'exchange') {
                    $stmt = $conn->prepare("
                        UPDATE product_units
                        SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                        WHERE id = (
                            SELECT pux.id
                            FROM (
                                SELECT pu.id
                                FROM product_units pu
                                JOIN products p ON p.id = pu.product_id
                                WHERE pu.product_id = ? AND p.user_id = ?
                                ORDER BY pu.is_primary DESC, pu.id ASC
                                LIMIT 1
                            ) AS pux
                        )
                    ");
                    $stmt->bind_param("dii", $quantity, $product_id, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $conn->commit();
                setMessage('گەڕانەوەی کاڵا بە سەرکەوتووی تۆمار کرا', 'success');
                
            } catch (Exception $e) {
                $conn->rollback();
                setMessage('هەڵە لە تۆمارکردنی گەڕانەوەی کاڵا: ' . $e->getMessage(), 'danger');
            }
        } else {
            setMessage('زانیاری کاڵا یان بڕەکە نادروستە', 'danger');
        }
    }
    
    elseif ($action === 'update_status') {
        $return_id = intval($_POST['return_id']);
        $new_status = sanitizeInput($_POST['new_status']);
        $refund_amount = floatval($_POST['refund_amount'] ?? 0);
        
        $stmt = $conn->prepare("UPDATE product_returns SET status = ?, refund_amount = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sdii", $new_status, $refund_amount, $return_id, $userId);
        
        if ($stmt->execute()) {
            setMessage('دۆخی گەڕانەوەکە نوێ کرایەوە', 'success');
        } else {
            setMessage('هەڵە لە نوێکردنەوەی دۆخ', 'danger');
        }
        $stmt->close();
    }
    
    elseif ($action === 'delete_return') {
        $return_id = intval($_POST['return_id']);
        
        // وەرگرتنی زانیاری گەڕانەوەکە
        $stmt = $conn->prepare("SELECT * FROM product_returns WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $return_id, $userId);
        $stmt->execute();
        $return_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($return_data && $return_data['status'] === 'pending') {
            $conn->begin_transaction();
            
            try {
                // سڕینەوەی گەڕانەوەکە
                $stmt = $conn->prepare("DELETE FROM product_returns WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $return_id, $userId);
                $stmt->execute();
                $stmt->close();
                
                // لایبردنی کاڵا لە ستۆک ئەگەر پێشتر زیاد کرابوو
                if ($return_data['return_type'] === 'refund' || $return_data['return_type'] === 'exchange') {
                    $stmt = $conn->prepare("
                        UPDATE product_units
                        SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                        WHERE id = (
                            SELECT pux.id
                            FROM (
                                SELECT pu.id
                                FROM product_units pu
                                JOIN products p ON p.id = pu.product_id
                                WHERE pu.product_id = ? AND p.user_id = ?
                                ORDER BY pu.is_primary DESC, pu.id ASC
                                LIMIT 1
                            ) AS pux
                        )
                        AND stock_quantity >= ?
                    ");
                    $stmt->bind_param("diii", $return_data['quantity'], $return_data['product_id'], $userId, $return_data['quantity']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $conn->commit();
                setMessage('گەڕانەوەکە بە سەرکەوتووی سڕایەوە', 'success');
                
            } catch (Exception $e) {
                $conn->rollback();
                setMessage('هەڵە لە سڕینەوەی گەڕانەوەکە: ' . $e->getMessage(), 'danger');
            }
        } else {
            setMessage('تەنها گەڕانەوە چاوەڕوانەکان دەتوانرێن بسڕدرێنەوە', 'warning');
        }
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// وەرگرتنی فیلتەرەکان
$status_filter = $_GET['status'] ?? '';
$return_type_filter = $_GET['return_type'] ?? '';
$customer_filter = intval($_GET['customer_id'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// وەرگرتنی لیستی کڕیاران بۆ فیلتەر
$customers_list = [];
$result = $conn->query("SELECT id, name FROM customers WHERE user_id = $userId ORDER BY name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $customers_list[] = $row;
    }
}

// وەرگرتنی لیستی کاڵاکان
$products_list = [];
$result = $conn->query("
    SELECT p.id, p.name, COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) AS sell_price
    FROM products p
    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
    LEFT JOIN product_units pu_any ON pu_any.id = (
        SELECT pu2.id
        FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    WHERE p.user_id = $userId
    ORDER BY p.name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products_list[] = $row;
    }
}

// دروستکردنی ویری
$whereClause = "WHERE pr.user_id = $userId";
$params = [];
$types = "";

if (!empty($status_filter)) {
    $whereClause .= " AND pr.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($return_type_filter)) {
    $whereClause .= " AND pr.return_type = ?";
    $params[] = $return_type_filter;
    $types .= "s";
}

if ($customer_filter > 0) {
    $whereClause .= " AND pr.customer_id = ?";
    $params[] = $customer_filter;
    $types .= "i";
}

if (!empty($date_from)) {
    $whereClause .= " AND DATE(pr.return_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $whereClause .= " AND DATE(pr.return_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if (!empty($search)) {
    $whereClause .= " AND (pr.customer_name LIKE ? OR pr.product_name LIKE ? OR pr.return_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

// وەرگرتنی گەڕانەوەکان
$sql = "SELECT pr.*, c.name as customer_name_full, c.phone as customer_phone, p.name as current_product_name
        FROM product_returns pr 
        LEFT JOIN customers c ON pr.customer_id = c.id 
        LEFT JOIN products p ON pr.product_id = p.id
        $whereClause 
        ORDER BY pr.return_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$returns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ئاماری گەڕانەوەکان
$stats = [];
$statsQuery = "SELECT 
                COUNT(*) as total_returns,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_returns,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_returns,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_returns,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_returns,
                COALESCE(SUM(total_amount), 0) as total_return_amount,
                COALESCE(SUM(refund_amount), 0) as total_refund_amount,
                COALESCE(SUM(quantity), 0) as total_quantity
               FROM product_returns WHERE user_id = $userId";
$result = $conn->query($statsQuery);
$stats = $result->fetch_assoc();

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            
            <!-- پەیامەکان -->
            <?php if ($message = getMessage()): ?>
                <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle-fill"></i>
                    <?php echo $message['message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- سەرنووسە -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="bi bi-arrow-return-left text-info"></i> بەڕێوەبردنی گەڕانەوەی کاڵاکان</h1>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                    <i class="bi bi-plus-circle"></i> زیادکردنی گەڕانەوەی نوێ
                </button>
            </div>

            <!-- کارتەکانی ئاماری -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-0 shadow-sm bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 opacity-75">گشتی گەڕانەوەکان</h6>
                                    <h2 class="card-title mb-0"><?php echo number_format($stats['total_returns']); ?></h2>
                                </div>
                                <div class="opacity-75">
                                    <i class="bi bi-arrow-return-left display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-0 shadow-sm bg-warning text-dark">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 opacity-75">چاوەڕوان</h6>
                                    <h2 class="card-title mb-0"><?php echo number_format($stats['pending_returns']); ?></h2>
                                </div>
                                <div class="opacity-75">
                                    <i class="bi bi-clock display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-0 shadow-sm bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 opacity-75">تەواو بووە</h6>
                                    <h2 class="card-title mb-0"><?php echo number_format($stats['completed_returns']); ?></h2>
                                </div>
                                <div class="opacity-75">
                                    <i class="bi bi-check-circle display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card border-0 shadow-sm bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 opacity-75">بڕی گەڕاوە</h6>
                                    <h2 class="card-title mb-0"><?php echo number_format($stats['total_return_amount'], 0); ?></h2>
                                    <small class="opacity-75">د.ع</small>
                                </div>
                                <div class="opacity-75">
                                    <i class="bi bi-currency-dollar display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فیلتەرەکان -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">دۆخ</label>
                            <select class="form-select" name="status">
                                <option value="">هەموو دۆخەکان</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>چاوەڕوان</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>پەسەندکراو</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>تەواو بووە</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>ڕەتکراوەتەوە</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">جۆری گەڕانەوە</label>
                            <select class="form-select" name="return_type">
                                <option value="">هەموو جۆرەکان</option>
                                <option value="refund" <?php echo $return_type_filter === 'refund' ? 'selected' : ''; ?>>گەڕاندنەوەی پارە</option>
                                <option value="exchange" <?php echo $return_type_filter === 'exchange' ? 'selected' : ''; ?>>گۆڕین</option>
                                <option value="store_credit" <?php echo $return_type_filter === 'store_credit' ? 'selected' : ''; ?>>کڕەدیتی فرۆشگا</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">کڕیار</label>
                            <select class="form-select" name="customer_id">
                                <option value="">هەموو کڕیارەکان</option>
                                <?php foreach ($customers_list as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" <?php echo $customer_filter == $customer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">لە بەرواری</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تا بەرواری</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">گەڕان</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ناو یان ژمارە">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> گەڕان
                            </button>
                            <a href="product_returns.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> ڕێسێت
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- تەیبڵی گەڕانەوەکان -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">لیستی گەڕانەوەکان (<?php echo count($returns); ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>ژمارەی گەڕانەوە</th>
                                    <th>کڕیار</th>
                                    <th>کاڵا</th>
                                    <th>بڕ</th>
                                    <th>بەهای گشتی</th>
                                    <th>جۆری گەڕانەوە</th>
                                    <th>هۆکار</th>
                                    <th>دۆخ</th>
                                    <th>بەروار</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($returns)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4">
                                            <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
                                            <h5 class="text-muted">هیچ گەڕانەوەیەک نەدۆزرایەوە</h5>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($returns as $return): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($return['customer_name_full'] ?: $return['customer_name']); ?></strong>
                                                <?php if (!empty($return['customer_phone'])): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($return['customer_phone']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($return['current_product_name'] ?: $return['product_name']); ?></strong>
                                                <small class="text-muted d-block"><?php echo number_format($return['unit_price'], 0); ?> د.ع / دانە</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo number_format($return['quantity']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo number_format($return['total_amount'], 0); ?> د.ع</strong>
                                                <?php if ($return['refund_amount'] > 0): ?>
                                                    <small class="text-success d-block">گەڕاوە: <?php echo number_format($return['refund_amount'], 0); ?> د.ع</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $typeColors = [
                                                    'refund' => 'warning',
                                                    'exchange' => 'info',
                                                    'store_credit' => 'secondary'
                                                ];
                                                $typeLabels = [
                                                    'refund' => 'گەڕاندنەوەی پارە',
                                                    'exchange' => 'گۆڕین',
                                                    'store_credit' => 'کڕەدیتی فرۆشگا'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $typeColors[$return['return_type']]; ?>">
                                                    <?php echo $typeLabels[$return['return_type']]; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($return['return_reason'] ?: '-'); ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $statusColors = [
                                                    'pending' => 'warning',
                                                    'approved' => 'info',
                                                    'completed' => 'success',
                                                    'rejected' => 'danger'
                                                ];
                                                $statusLabels = [
                                                    'pending' => 'چاوەڕوان',
                                                    'approved' => 'پەسەندکراو',
                                                    'completed' => 'تەواو بووە',
                                                    'rejected' => 'ڕەتکراوەتەوە'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $statusColors[$return['status']]; ?>">
                                                    <?php echo $statusLabels[$return['status']]; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo formatDate($return['return_date']); ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if ($return['status'] === 'pending'): ?>
                                                        <button type="button" class="btn btn-success btn-sm" onclick="updateStatus(<?php echo $return['id']; ?>, 'approved', <?php echo $return['total_amount']; ?>)">
                                                            <i class="bi bi-check"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-sm" onclick="updateStatus(<?php echo $return['id']; ?>, 'rejected', 0)">
                                                            <i class="bi bi-x"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($return['status'] === 'approved'): ?>
                                                        <button type="button" class="btn btn-info btn-sm" onclick="updateStatus(<?php echo $return['id']; ?>, 'completed', <?php echo $return['total_amount']; ?>)">
                                                            <i class="bi bi-check-circle"></i> تەواو
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($return['status'] === 'pending'): ?>
                                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteReturn(<?php echo $return['id']; ?>, '<?php echo htmlspecialchars($return['return_number']); ?>')">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- مۆداڵی زیادکردنی گەڕانەوە -->
<div class="modal fade" id="addReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_return">
                <div class="modal-header">
                    <h5 class="modal-title">زیادکردنی گەڕانەوەی نوێ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">کڕیار</label>
                                <select class="form-select" name="customer_id">
                                    <option value="">کڕیارێک هەڵبژێرە</option>
                                    <?php foreach ($customers_list as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">ناوی کڕیار (ئەگەر لە لیست نییە)</label>
                                <input type="text" class="form-control" name="customer_name" placeholder="ناوی کڕیار">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">کاڵا <span class="text-danger">*</span></label>
                                <select class="form-select" name="product_id" id="product_select" required>
                                    <option value="">کاڵایەک هەڵبژێرە</option>
                                    <?php foreach ($products_list as $product): ?>
                                        <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['sell_price']; ?>">
                                            <?php echo htmlspecialchars($product['name']); ?> - <?php echo number_format($product['sell_price'], 0); ?> د.ع
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">بڕ <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="quantity" id="quantity_input" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">نرخی یەکە (د.ع) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="unit_price" id="unit_price_input" step="0.001" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">جۆری گەڕانەوە <span class="text-danger">*</span></label>
                                <select class="form-select" name="return_type" required>
                                    <option value="">جۆرێک هەڵبژێرە</option>
                                    <option value="refund">گەڕاندنەوەی پارە</option>
                                    <option value="exchange">گۆڕین</option>
                                    <option value="store_credit">کڕەدیتی فرۆشگا</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">ژمارەی فرۆشتنی ئەسڵی</label>
                                <input type="number" class="form-control" name="original_sale_id" placeholder="ژمارەی فرۆشتن">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">هۆکاری گەڕانەوە</label>
                        <input type="text" class="form-control" name="return_reason" placeholder="هۆکاری گەڕانەوە">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تێبینی</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="تێبینی زیاتر"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>بەهای گشتی:</strong> <span id="total_amount_display">0</span> د.ع
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                    <button type="submit" class="btn btn-primary">زیادکردن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// نوێکردنەوەی نرخ کاتێک کاڵا هەڵدەبژێردرێت
document.getElementById('product_select')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const price = selectedOption.getAttribute('data-price') || 0;
    document.getElementById('unit_price_input').value = price;
    calculateTotal();
});

// حیسابکردنی بەهای گشتی
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity_input')?.value || 0);
    const unitPrice = parseFloat(document.getElementById('unit_price_input')?.value || 0);
    const total = quantity * unitPrice;
    
    document.getElementById('total_amount_display').textContent = total.toLocaleString();
}

// گوێگرتن بۆ گۆڕانی بڕ و نرخ
document.getElementById('quantity_input')?.addEventListener('input', calculateTotal);
document.getElementById('unit_price_input')?.addEventListener('input', calculateTotal);

// نوێکردنەوەی دۆخ
function updateStatus(returnId, newStatus, refundAmount) {
    let message = '';
    
    switch(newStatus) {
        case 'approved':
            message = 'ئایا دڵنیایت لە پەسەندکردنی گەڕانەوەکە؟';
            break;
        case 'rejected':
            message = 'ئایا دڵنیایت لە ڕەتکردنەوەی گەڕانەوەکە؟';
            break;
        case 'completed':
            message = 'ئایا دڵنیایت لە تەواوکردنی گەڕانەوەکە؟';
            break;
    }
    
    if (confirm(message)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="return_id" value="${returnId}">
            <input type="hidden" name="new_status" value="${newStatus}">
            <input type="hidden" name="refund_amount" value="${refundAmount}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// سڕینەوەی گەڕانەوە
function deleteReturn(returnId, returnNumber) {
    if (confirm('ئایا دڵنیایت لە سڕینەوەی گەڕانەوەکە "' + returnNumber + '"؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_return">
            <input type="hidden" name="return_id" value="${returnId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../includes/footer.php'; ?>